<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Resources;

use Nateyk\Chapa\Http\ApiResponse;
use Nateyk\Chapa\Http\HttpClient;
use Nateyk\Chapa\Support\RequestValidator as Validate;

final class Subaccounts
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): ApiResponse
    {
        Validate::nonEmpty($payload, 'subaccount_name');
        Validate::currency($payload['currency'] ?? null);
        foreach (['split_type', 'split_value', 'account'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new \InvalidArgumentException('Missing subaccount field: '.$field);
            }
        }
        return $this->http->request('POST', '/v2/subaccounts', body: $payload);
    }

    /** @param array<string, mixed> $payload */
    public function update(string $reference, array $payload): ApiResponse
    {
        if ($payload === []) {
            throw new \InvalidArgumentException('Subaccount update requires at least one field.');
        }
        return $this->http->request('PUT', '/v2/subaccounts/'.Validate::reference($reference), body: $payload);
    }

    /** @param array<string, mixed> $query */
    public function list(array $query = []): ApiResponse
    {
        Validate::limit($query);
        return $this->http->request('GET', '/v2/subaccounts', query: $query);
    }
}
