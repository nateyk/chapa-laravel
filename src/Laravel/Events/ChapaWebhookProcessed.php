<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel\Events;

use Nateyk\Chapa\Dto\VerifiedTransaction;
use Nateyk\Chapa\Webhooks\WebhookEvent;

/**
 * Fired after Chapa verification for payments/payouts; refund events need refund-reference reconciliation.
 * Listeners must guard their own domain writes with unique constraints and status ordering.
 * A duplicate dispatch is possible if the worker crashes before acknowledging processing.
 */
final readonly class ChapaWebhookProcessed
{
    public function __construct(
        public int $receiptId,
        public WebhookEvent $webhook,
        public ?VerifiedTransaction $verification,
    ) {
    }

    public function paymentMatches(int $amountCents, string $currency, string $merchantReference): bool
    {
        return $this->webhook->signalsSuccessfulPayment()
            && $this->verification?->matchesPayment($amountCents, $currency, $merchantReference) === true;
    }
}
