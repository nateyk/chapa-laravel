<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Webhooks;

use Nateyk\Chapa\Dto\VerifiedTransaction;

/** Final webhook status is never authoritative over a newer server-side verification. */
final class WebhookConsistency
{
    /** @var array<string, string> */
    private const TERMINAL_STATUS = [
        'payment.success' => 'SUCCESS',
        'payment.failed' => 'FAILED',
        'payment.cancelled' => 'CANCELLED',
        'payment.incomplete' => 'INCOMPLETE',
        'payment.blocked' => 'BLOCKED',
        'payout.success' => 'SUCCESS',
        'payout.failed' => 'FAILED',
        'payout.reversed' => 'REVERSED',
        'payout.blocked' => 'BLOCKED',
    ];

    public static function assertCurrent(WebhookEvent $event, VerifiedTransaction $verified): void
    {
        if ($event->type !== $verified->kind) {
            throw new \UnexpectedValueException('Webhook verification resource type mismatch.');
        }
        $expected = self::TERMINAL_STATUS[$event->name] ?? null;
        if ($expected !== null && $verified->status !== $expected) {
            // Fail closed; safe worker retries eventually require human reconciliation.
            // Never dispatch a stale FAILED event when Chapa verification reports SUCCESS.
            throw new \UnexpectedValueException('Webhook status differs from latest Chapa verification.');
        }
    }
}
