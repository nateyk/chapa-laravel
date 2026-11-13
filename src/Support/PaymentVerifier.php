<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Support;

use Nateyk\Chapa\Http\ApiResponse;

final class PaymentVerifier
{
    /** Check all four dimensions before fulfilling an order. Call payments()->verify() first. */
    public static function matches(ApiResponse $verification, int $amountCents, string $currency, string $merchantReference): bool
    {
        $data = $verification->data();

        return $verification->operationSucceeded()
            && is_array($data)
            && ($data['status'] ?? null) === 'SUCCESS'
            && ($data['amount'] ?? null) === $amountCents
            && ($data['currency'] ?? null) === $currency
            && ($data['merchant_reference'] ?? null) === $merchantReference;
    }
}
