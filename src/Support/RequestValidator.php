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

    /**
     * @param array<string, mixed> $payload
     * When both are supplied Chapa uses the inline account details.
     */
    public static function payoutDestination(array $payload): void
    {
        $account = $payload['account'] ?? null;
        $reference = $payload['account_reference'] ?? null;
        if ($account === null && $reference === null) {
            throw new \InvalidArgumentException('Payout requires an account or account_reference.');
        }
        if ($reference !== null && (!is_string($reference) || trim($reference) === '')) {
            throw new \InvalidArgumentException('account_reference must be a nonempty string.');
        }
        if ($account !== null) {
            if (!is_array($account) || $account === [] || array_is_list($account)) {
                throw new \InvalidArgumentException('Payout account must be an object.');
            }
            foreach (['account_number', 'account_name', 'bank_slug'] as $field) {
                self::nonEmpty($account, $field);
            }
            if (array_key_exists('bank_name', $account) && !is_string($account['bank_name'])) {
                throw new \InvalidArgumentException('bank_name must be a string.');
            }
        }
        if (array_key_exists('reason', $payload) && !is_string($payload['reason'])) {
            throw new \InvalidArgumentException('Payout reason must be a string.');
        }
        if (array_key_exists('meta', $payload) &&
            (!is_array($payload['meta']) || ($payload['meta'] !== [] && array_is_list($payload['meta'])))) {
            throw new \InvalidArgumentException('Payout meta must be an object.');
        }
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
