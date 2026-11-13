<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Cli;

use Nateyk\Chapa\Support\WebhookVerifier;
use Nateyk\Chapa\Webhooks\WebhookEvent;
use Nateyk\Chapa\Webhooks\WebhookStateGuard;

/** In-memory replay of synthetic Chapa v2 webhooks; never exposes an HTTP route. */
final class WebhookReplayHarness
{
    private WebhookVerifier $verifier;
    /** @var array<string, bool> */
    private array $seen = [];
    /** @var array<string, WebhookEvent> */
    private array $latest = [];

    public function __construct(private readonly string $mode = 'test', private readonly string $integration = 'offline-test')
    {
        if (!in_array($mode, ['test', 'live'], true) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $integration) !== 1) {
            throw new \InvalidArgumentException('Invalid replay scope.');
        }
        $this->verifier = new WebhookVerifier('SYNTHETIC_WEBHOOK_SECRET_NOT_FOR_NETWORK');
    }

    /** @param array<string,mixed> $data */
    public function receive(array $data, bool $tamper = false): string
    {
        $raw = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $sig = hash_hmac('sha256', $raw, 'SYNTHETIC_WEBHOOK_SECRET_NOT_FOR_NETWORK');
        // Sign valid content then deliberately corrupt bytes, to simulate forged/replayed deliveries.
        if ($tamper) {
            $raw .= ' ';
        }
        try {
            $event = $this->verifier->event($raw, $sig);
        } catch (\Throwable) {
            return 'rejected_signature_or_payload';
        }
        if ($event->mode !== null && $event->mode !== $this->mode) {
            return 'rejected_environment';
        }
        $fp = hash('sha256', $this->integration."\0".$this->mode."\0".$raw);
        if (isset($this->seen[$fp])) {
            return 'duplicate';
        }
        $this->seen[$fp] = true;
        $reference = $event->chapaReference ?? $event->merchantReference;
        if ($reference === null) {
            return 'review_missing_reference';
        }
        $bucket = $event->type."\0".$reference;
        $decision = WebhookStateGuard::decide($event, $this->latest[$bucket] ?? null);
        if ($decision === WebhookStateGuard::APPLY) {
            $this->latest[$bucket] = $event;
            return 'accepted_for_reconciliation';
        }
        if ($decision === WebhookStateGuard::IGNORE_DUPLICATE) {
            return 'duplicate';
        }
        if ($decision === WebhookStateGuard::IGNORE_STALE) {
            return 'ignored_stale';
        }
        return 'review_ordering';
    }
}
