<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Nateyk\Chapa\Http\RawResponse;
use Nateyk\Chapa\Http\Transport;

final class MockTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: int}> */
    public array $requests = [];

    /** @var list<RawResponse> */
    public array $responses = [];

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): RawResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeout');

        return array_shift($this->responses) ?? new RawResponse(200, '{"status":"success","data":{"status":"PENDING"}}');
    }
}
