<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Dto;

use Nateyk\Chapa\Support\RequestValidator;

/** @internal */
final class RequestFields
{
    /** @param array<string, mixed> $fields @return array<string, mixed> */
    public static function stripNullAndEmptyArrays(array $fields): array
    {
        return array_filter($fields, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public static function validateMoney(int $amount, string $currency, ?string $reference): void
    {
        $fields = ['amount' => $amount, 'currency' => $currency];
        if ($reference !== null) {
            $fields['merchant_reference'] = $reference;
        }
        RequestValidator::money($fields);
    }

    public static function httpsUrl(?string $url, string $name): void
    {
        if ($url === null) {
            return;
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException($name.' must be an absolute HTTPS URL without credentials.');
        }
    }
}
