<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Http;

interface Transport
{
    /** @param array<string, string> $headers */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): RawResponse;
}
