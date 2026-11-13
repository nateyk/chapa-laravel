<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Support;

final class RequestValidator
{
    /** @param array<string, mixed> $payload */
    public static function money(array $payload, bool $requiresCurrency = true): void
    {
        if (!array_key_exists('amount', $payload) || !is_int($payload['amount']) || $payload['amount'] < 100) {
            throw new \InvalidArgumentException('Amount must be integer cents of at least 100.');
        }
        if ($requiresCurrency) {
            self::currency($payload['currency'] ?? null);
        } elseif (array_key_exists('currency', $payload)) {
            self::currency($payload['currency']);
        }
        self::merchantReference($payload);
    }

    public static function currency(mixed $currency): void
    {
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new \InvalidArgumentException('Currency must be a three-letter uppercase ISO code.');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function merchantReference(array $payload): void
    {
        if (array_key_exists('merchant_reference', $payload)
            && (!is_string($payload['merchant_reference']) || trim($payload['merchant_reference']) === ''
                || strlen($payload['merchant_reference']) > 20)) {
            throw new \InvalidArgumentException('Merchant reference must be a nonempty string of 20 bytes or less.');
        }
    }

    public static function reference(string $reference): string
    {
        if (preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $reference) !== 1) {
            throw new \InvalidArgumentException('Reference contains unsupported characters.');
        }

        return rawurlencode($reference);
    }

    /** @param array<string, mixed> $payload */
    public static function nonEmpty(array $payload, string $field): void
    {
        if (!isset($payload[$field]) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
            throw new \InvalidArgumentException($field.' must be a nonempty string.');
        }
    }

    /** @param array<string, mixed> $query */
    public static function limit(array $query): void
    {
        if (array_key_exists('limit', $query) && (!is_int($query['limit']) || $query['limit'] < 1)) {
            throw new \InvalidArgumentException('List limit must be a positive integer.');
        }
    }
}
