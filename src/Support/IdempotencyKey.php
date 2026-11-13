<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Support;

final class IdempotencyKey
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function validate(string $key): string
    {
        if (strlen($key) < 1 || strlen($key) > 255 || preg_match('/^[\x21-\x7E]+$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Idempotency key must be 1–255 printable ASCII characters without spaces.');
        }

        return $key;
    }
}
