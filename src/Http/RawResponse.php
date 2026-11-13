<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Http;

final class RawResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
    ) {
    }
}
