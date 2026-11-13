<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Http;

final class ApiResponse
{
    /** @param array<string, mixed> $payload */
    public function __construct(public readonly int $statusCode, private readonly array $payload)
    {
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return $this->payload;
    }

    public function data(): mixed
    {
        return $this->payload['data'] ?? null;
    }

    /** Top-level operation success does not indicate the money moved. */
    public function operationSucceeded(): bool
    {
        return $this->statusCode >= 200
            && $this->statusCode < 300
            && ($this->payload['status'] ?? null) === 'success';
    }
}
