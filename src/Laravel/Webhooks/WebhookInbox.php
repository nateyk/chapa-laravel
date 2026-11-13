<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel\Webhooks;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Nateyk\Chapa\ChapaClient;

/** Durable receipt: authenticate first, deduplicate atomically, then acknowledge. */
final class WebhookInbox
{
    public function __construct(private readonly ChapaClient $chapa)
    {
    }

    public function accept(string $rawBody, ?string $signature): ReceivedWebhook
    {
        $maxBytes = (int) config('chapa.webhook_inbox.max_bytes', 131072);
        if ($maxBytes < 1 || strlen($rawBody) > $maxBytes) {
            throw new \LengthException('Chapa webhook exceeds configured request size limit.');
        }

        // Never trust JSON fields before checking the raw-body signature.
        $event = $this->chapa->webhooks()->event($rawBody, $signature);
        $expectedMode = (string) config('chapa.webhook_inbox.mode', 'test');
        $integration = (string) config('chapa.webhook_inbox.integration', 'default');
        if (!in_array($expectedMode, ['live', 'test'], true) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $integration) !== 1) {
            throw new \LogicException('Invalid Chapa webhook inbox configuration.');
        }
        if ($event->mode !== null && $event->mode !== $expectedMode) {
            throw new \UnexpectedValueException('Chapa webhook environment mismatch.');
        }

        // Namespace fingerprints by integration AND mode; different partial refunds remain distinct.
        $fingerprint = hash('sha256', "chapa-v2\0".$integration."\0".$expectedMode."\0".$rawBody);
        $now = now();
        $inserted = DB::table('chapa_webhook_receipts')->insertOrIgnore([
            'integration' => $integration,
            'mode' => $expectedMode,
            'fingerprint' => $fingerprint,
            'webhook_type' => $event->type,
            'event_name' => $event->name,
            'merchant_reference' => $event->merchantReference,
            'chapa_reference' => $event->chapaReference,
            'event_updated_at' => $event->updatedAt,
            'payload_encrypted' => Crypt::encryptString($rawBody),
            'state' => 'pending',
            'attempts' => 0,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = DB::table('chapa_webhook_receipts')
            ->where('integration', $integration)
            ->where('mode', $expectedMode)
            ->where('fingerprint', $fingerprint)
            ->value('id');
        if ($id === null) {
            throw new \RuntimeException('Could not persist the authenticated Chapa webhook delivery.');
        }

        return new ReceivedWebhook((int) $id, $inserted === 1, $event);
    }
}
