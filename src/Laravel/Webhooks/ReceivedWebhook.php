<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel\Webhooks;

use Nateyk\Chapa\Webhooks\WebhookEvent;

final readonly class ReceivedWebhook
{
    public function __construct(
        public int $receiptId,
        public bool $newDelivery,
        public WebhookEvent $event,
    ) {
    }
}
