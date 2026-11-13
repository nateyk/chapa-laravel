<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Webhooks;

/**
 * Optional guidance for application-level domain state reconciliation.
 * The durable inbox still deduplicates by signed raw-body hash. This guard is not
 * an alternative to verifying current payment/payout state with Chapa.
 */
final class WebhookStateGuard
{
    public const APPLY = 'apply';
    public const IGNORE_DUPLICATE = 'ignore_duplicate';
    public const IGNORE_STALE = 'ignore_stale';
    public const REVIEW = 'review';

    public static function decide(WebhookEvent $incoming, ?WebhookEvent $previous): string
    {
        if ($previous === null) {
            return self::APPLY;
        }
        if ($previous->type !== $incoming->type
            || ($previous->chapaReference !== null && $incoming->chapaReference !== $previous->chapaReference)
            || ($previous->merchantReference !== null && $incoming->merchantReference !== $previous->merchantReference)
            || ($previous->mode !== null && $incoming->mode !== $previous->mode)) {
            return self::REVIEW;
        }
        if ($previous->payload === $incoming->payload) {
            return self::IGNORE_DUPLICATE;
        }
        $old = self::timestamp($previous->updatedAt);
        $new = self::timestamp($incoming->updatedAt);
        if ($old === null || $new === null) {
            return self::REVIEW;
        }
        if ($new < $old) {
            return self::IGNORE_STALE;
        }
        if ($new === $old) {
            return self::REVIEW;
        }
        // Never replace a previously successful payment with a later failure-like notification.
        // Legitimate refund events use webhook_type=refund and require separate reconciliation.
        if ($incoming->type === 'payment' && $previous->signalsSuccessfulPayment()
            && !$incoming->signalsSuccessfulPayment()) {
            return self::REVIEW;
        }
        // Payout reversals after success are valid and should not be discarded.
        return self::APPLY;
    }

    private static function timestamp(?string $timestamp): ?float
    {
        if ($timestamp === null || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $timestamp) !== 1) {
            return null;
        }
        try {
            return (float) (new \DateTimeImmutable($timestamp))->format('U.u');
        } catch (\Exception) {
            return null;
        }
    }
}
