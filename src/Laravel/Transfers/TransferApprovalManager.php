<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel\Transfers;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Dto\PayoutInitiation;
use Nateyk\Chapa\Dto\PayoutRequest;
use Nateyk\Chapa\Support\IdempotencyKey;
use Nateyk\Chapa\Support\RequestValidator;

/**
 * Optional merchant-side admin authorization ledger. Does not register admin routes,
 * authenticate admins, or automatically send money. The consuming app must authorize
 * all calls to approve/reject/dispatch before invoking them.
 */
final class TransferApprovalManager
{
    public function __construct(private readonly ChapaClient $chapa)
    {
    }

    /** @param array<string, mixed>|PayoutRequest $payout */
    public function request(array|PayoutRequest $payout, string $requester, int $expiresInMinutes = 1440): int
    {
        $this->assertEnabled();
        $payload = $payout instanceof PayoutRequest ? $payout->toArray() : $payout;
        RequestValidator::money($payload);
        RequestValidator::payoutDestination($payload);
        $reference = $payload['merchant_reference'] ?? null;
        if (!is_string($reference) || preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $reference) !== 1) {
            throw new \InvalidArgumentException('Admin-approved payouts need a unique 1-20 character merchant_reference.');
        }
        // Chapa's legacy approval callback supplies raw account fields, not a saved account_reference.
        if (!is_array($payload['account'] ?? null)) {
            throw new \InvalidArgumentException('An inline account is required for server approval matching.');
        }
        if (!is_string($requester) || trim($requester) === '' || strlen($requester) > 120
            || $expiresInMinutes < 1 || $expiresInMinutes > 10080) {
            throw new \InvalidArgumentException('Invalid requester or approval expiry.');
        }
        [$integration, $mode] = $this->scope();
        $now = now();
        return (int) DB::table('chapa_transfer_approvals')->insertGetId([
            'integration' => $integration,
            'mode' => $mode,
            'merchant_reference' => $reference,
            'amount_cents' => $payload['amount'],
            'currency' => $payload['currency'],
            'payload_encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            'idempotency_key' => IdempotencyKey::generate(),
            'state' => 'requested',
            'requester' => trim($requester),
            'expires_at' => $now->copy()->addMinutes($expiresInMinutes),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Host must authorize an admin before calling. Provides only review-safe,
     * masked destination details, never account numbers or raw encrypted JSON.
     *
     * @return list<array<string, int|string|null>>
     */
    public function reviewQueue(int $limit = 50): array
    {
        $this->assertEnabled();
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Review limit must be between 1 and 100.');
        }
        [$integration, $mode] = $this->scope();
        $records = DB::table('chapa_transfer_approvals')
            ->where('integration', $integration)->where('mode', $mode)
            ->whereIn('state', ['requested', 'approved', 'unknown'])
            ->orderByDesc('id')->limit($limit)->get();
        $result = [];
        foreach ($records as $record) {
            $payload = json_decode(Crypt::decryptString($record->payload_encrypted), true, 64, JSON_THROW_ON_ERROR);
            $account = is_array($payload) && is_array($payload['account'] ?? null) ? $payload['account'] : [];
            $number = is_string($account['account_number'] ?? null) ? $account['account_number'] : '';
            $result[] = [
                'id' => (int) $record->id,
                'merchant_reference' => $record->merchant_reference,
                'amount_cents' => (int) $record->amount_cents,
                'currency' => $record->currency,
                'state' => $record->state,
                'requester' => $record->requester,
                'account_name' => is_string($account['account_name'] ?? null) ? $account['account_name'] : '',
                'bank_slug' => is_string($account['bank_slug'] ?? null) ? $account['bank_slug'] : '',
                'account_number_masked' => $number === '' ? '' : str_repeat('*', max(0, strlen($number) - 4)).substr($number, -4),
                'expires_at' => (string) $record->expires_at,
            ];
        }
        return $result;
    }

    /** Admin must be authorized using the host app's Gate/policy BEFORE calling. */
    public function approve(string $merchantReference, string $adminActor): bool
    {
        $this->assertEnabled();
        $this->assertActor($adminActor);
        [$integration, $mode] = $this->scope();
        return DB::table('chapa_transfer_approvals')
            ->where('integration', $integration)->where('mode', $mode)
            ->where('merchant_reference', $merchantReference)->where('state', 'requested')
            ->where('requester', '<>', trim($adminActor))
            ->where('expires_at', '>', now())
            ->update(['state' => 'approved', 'approver' => trim($adminActor),
                'approved_at' => now(), 'updated_at' => now()]) === 1;
    }

    /** Rejection is possible only before dispatch starts. */
    public function reject(string $merchantReference, string $adminActor): bool
    {
        $this->assertEnabled();
        $this->assertActor($adminActor);
        [$integration, $mode] = $this->scope();
        return DB::table('chapa_transfer_approvals')
            ->where('integration', $integration)->where('mode', $mode)
            ->where('merchant_reference', $merchantReference)->whereIn('state', ['requested', 'approved'])
            ->update(['state' => 'rejected', 'approver' => trim($adminActor), 'updated_at' => now()]) === 1;
    }

    /**
     * Host must authorize this call and dispatch only after admin approval.
     * A unique DB claim and durable, reused idempotency key prevent concurrent POSTs.
     * On ambiguous HTTP/network failure, mark UNKNOWN; do not silently retry.
     */
    public function dispatchApproved(string $merchantReference): PayoutInitiation
    {
        $this->assertEnabled();
        [$integration, $mode] = $this->scope();
        $now = now();
        $claim = DB::table('chapa_transfer_approvals')
            ->where('integration', $integration)->where('mode', $mode)
            ->where('merchant_reference', $merchantReference)->where('state', 'approved')
            ->where('expires_at', '>', $now)
            ->update(['state' => 'sending', 'dispatch_started_at' => $now, 'updated_at' => $now]);
        if ($claim !== 1) {
            throw new \LogicException('Transfer must have a valid admin approval before dispatch.');
        }
        $record = DB::table('chapa_transfer_approvals')
            ->where('integration', $integration)->where('mode', $mode)
            ->where('merchant_reference', $merchantReference)->first();
        if ($record === null) {
            throw new \RuntimeException('Claimed payout approval was not found.');
        }
        try {
            $decoded = json_decode(Crypt::decryptString($record->payload_encrypted), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new \UnexpectedValueException('Approved payout payload is invalid.');
            }
            $result = $this->chapa->payouts()->createTyped($decoded, $record->idempotency_key);
            DB::table('chapa_transfer_approvals')->where('id', $record->id)->where('state', 'sending')->update([
                'state' => 'submitted', 'chapa_reference' => $result->chapaReference,
                'submitted_at' => now(), 'updated_at' => now(),
            ]);
            return $result;
        } catch (\Throwable $exception) {
            DB::table('chapa_transfer_approvals')->where('id', $record->id)->where('state', 'sending')->update([
                'state' => 'unknown', 'updated_at' => now(),
            ]);
            throw $exception;
        }
    }

    /** @return array{string, string} */
    private function scope(): array
    {
        $integration = config('chapa.transfer_approval.integration', 'default');
        $mode = config('chapa.transfer_approval.mode', 'test');
        if (!is_string($integration) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $integration) !== 1
            || !in_array($mode, ['test', 'live'], true)) {
            throw new \LogicException('Invalid transfer approval integration or mode.');
        }
        return [$integration, $mode];
    }

    private function assertEnabled(): void
    {
        if (config('chapa.transfer_approval.enabled', false) !== true) {
            throw new \LogicException('Transfer approval integration is disabled.');
        }
    }

    private function assertActor(string $actor): void
    {
        if (trim($actor) === '' || strlen($actor) > 120) {
            throw new \InvalidArgumentException('An accountable admin actor is required.');
        }
    }
}
