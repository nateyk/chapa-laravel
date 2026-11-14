<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Support;

/**
 * Generate an unpredictable Chapa API v2 merchant reference (maximum 20 bytes).
 *
 * This does not replace a database unique constraint or a durable idempotency key.
 * Unlike legacy uniqid(), random_bytes() is suitable for unpredictable references.
 */
final class MerchantReference
{
    public static function generate(string $prefix = 'chp'): string
    {
        // Reserve at least 12 base64url characters (72 bits of entropy).
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,6}$/D', $prefix) !== 1) {
            throw new \InvalidArgumentException('Reference prefix must be 1–7 ASCII letters, digits, underscores or hyphens and start with a letter or digit.');
        }

        $remaining = 20 - strlen($prefix) - 1;
        $random = rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');

        return $prefix.'-'.substr($random, 0, $remaining);
    }
}
