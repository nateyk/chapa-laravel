<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Cli;

use Nateyk\Chapa\Http\RawResponse;
use Nateyk\Chapa\Http\Transport;

/** Pure in-memory transport. No socket or HTTP request is ever created. */
final class ScenarioTransport implements Transport
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $requests = [];

    /** @param list<RawResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): RawResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if ($this->responses === []) {
            throw new \LogicException('Offline scenario exhausted synthetic responses.');
        }
        return array_shift($this->responses);
    }
}
