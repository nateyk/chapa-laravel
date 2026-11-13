<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel\Webhooks;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Dto\VerifiedTransaction;
use Nateyk\Chapa\Laravel\Events\ChapaWebhookProcessed;
use Nateyk\Chapa\Webhooks\WebhookEvent;
use Nateyk\Chapa\Webhooks\WebhookConsistency;

/** Lease-based, retryable inbox processor; never calls an API while holding a database lock. */
final class WebhookProcessor
{
    public function __construct(private readonly ChapaClient $chapa)
    {
    }

    public function process(int $receiptId): bool
    {
        $now = now();
        $claimToken = bin2hex(random_bytes(16));
        $maxAttempts = max(1, (int) config('chapa.webhook_inbox.max_attempts', 8));
        // Atomic compare-and-claim ensures parallel workers cannot process this receipt concurrently.
        $claimed = DB::table('chapa_webhook_receipts')
            ->where('id', $receiptId)
            ->where('attempts', '<', $maxAttempts)
            ->where(static function ($query) use ($now): void {
                $query->where(static function ($available) use ($now): void {
                    $available->whereIn('state', ['pending', 'failed'])
                        ->where('available_at', '<=', $now);
                })->orWhere(static function ($expired) use ($now): void {
                    $expired->where('state', 'processing')->where('lease_until', '<=', $now);
                });
            })
            ->update([
                'state' => 'processing',
                'attempts' => DB::raw('attempts + 1'),
                'lease_until' => $now->copy()->addMinutes(5),
                'claim_token' => $claimToken,
                'updated_at' => $now,
            ]);
        if ($claimed !== 1) {
            return false;
        }

        $receipt = DB::table('chapa_webhook_receipts')->where('id', $receiptId)->first();
        if ($receipt === null) {
            return false;
        }

        try {
            $raw = Crypt::decryptString($receipt->payload_encrypted);
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) {
                throw new \UnexpectedValueException('Receipt body is not an event object.');
            }
            $event = WebhookEvent::fromArray($data);
            $verification = $this->verify($event);

            // A stale worker must never dispatch after a newer worker has reclaimed its lease.
            // The row lock makes state change and synchronous listener DB writes one transaction.
            $handled = DB::transaction(static function () use ($receiptId, $claimToken, $event, $verification): bool {
                $claim = DB::table('chapa_webhook_receipts')->where('id', $receiptId)
                    ->lockForUpdate()->first();
                if ($claim === null || $claim->state !== 'processing' || $claim->claim_token !== $claimToken) {
                    return false;
                }
                Event::dispatch(new ChapaWebhookProcessed($receiptId, $event, $verification));
                DB::table('chapa_webhook_receipts')->where('id', $receiptId)->where('claim_token', $claimToken)
                    ->update([
                        'state' => 'processed',
                        'lease_until' => null,
                        'claim_token' => null,
                        'last_error' => null,
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);
                return true;
            });
            return $handled;
        } catch (\Throwable $exception) {
            // Do not persist response bodies, credentials, customer data, or exception messages.
            $attempts = (int) $receipt->attempts;
            $delaySeconds = min(3600, 30 * (2 ** min($attempts, 7)));
            DB::table('chapa_webhook_receipts')->where('id', $receiptId)->where('state', 'processing')
                ->where('claim_token', $claimToken)->update([
                    'state' => 'failed',
                    'lease_until' => null,
                    'claim_token' => null,
                    'available_at' => now()->addSeconds($delaySeconds),
                    'last_error' => get_class($exception),
                    'updated_at' => now(),
                ]);
            return false;
        }
    }

    private function verify(WebhookEvent $event): ?VerifiedTransaction
    {
        if ($event->type === 'refund') {
            // Refund webhooks identify the payment, NOT the refund reference. Never guess.
            return null;
        }
        $reference = $event->chapaReference ?? $event->merchantReference;
        if ($reference === null) {
            throw new \UnexpectedValueException('A Chapa reference is required to reconcile this webhook.');
        }
        $verified = $event->type === 'payment'
            ? $this->chapa->payments()->verifyTyped($reference)
            : $this->chapa->payouts()->verifyTyped($reference);

        // Guard against a webhook/reference mismatch; receipt data alone is not authoritative.
        if ($event->merchantReference !== null && $verified->merchantReference !== $event->merchantReference) {
            throw new \UnexpectedValueException('Webhook merchant reference differs from verification.');
        }
        if ($event->chapaReference !== null && $verified->chapaReference !== $event->chapaReference) {
            throw new \UnexpectedValueException('Webhook Chapa reference differs from verification.');
        }
        if ($event->currency !== null && $verified->currency !== $event->currency) {
            throw new \UnexpectedValueException('Webhook currency differs from verification.');
        }
        if ($event->amount !== null && $verified->amount !== $event->amount) {
            throw new \UnexpectedValueException('Webhook amount differs from verification.');
        }
        // A valid signature and success notification may still precede a consistent verify response.
        // Retry instead of permanently acknowledging an unverified success event.
        if (($event->signalsSuccessfulPayment()
            || ($event->type === 'payout' && $event->name === 'payout.success' && $event->status === 'success'))
            && !$verified->succeeded()) {
            throw new \RuntimeException('Chapa reported success but verification is not yet final.');
        }
        // Do not dispatch contradictory terminal notifications to application listeners.
        // Chapa server-side verification is authoritative; failed attempts are retried.
        WebhookConsistency::assertCurrent($event, $verified);
        return $verified;
    }
}
