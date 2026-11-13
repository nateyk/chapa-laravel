<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Support;

use Nateyk\Chapa\Http\ApiResponse;
use Nateyk\Chapa\Dto\VerifiedTransaction;

final class PaymentVerifier
{
    /** Check all four dimensions before fulfilling an order. Call payments()->verify() first. */
    public static function matches(ApiResponse $verification, int $amountCents, string $currency, string $merchantReference): bool
    {
        try {
            return VerifiedTransaction::fromResponse($verification, 'payment')
                ->matchesPayment($amountCents, $currency, $merchantReference);
        } catch (\UnexpectedValueException) {
            return false;
        }
    }
}
