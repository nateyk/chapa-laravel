<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Resources;

use Nateyk\Chapa\Http\ApiResponse;
use Nateyk\Chapa\Http\HttpClient;
use Nateyk\Chapa\Support\RequestValidator as Validate;

final class Payouts
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload, string $idempotencyKey): ApiResponse
    {
        Validate::money($payload, requiresCurrency: false);
        if (!isset($payload['account']) && !isset($payload['account_reference'])) {
            throw new \InvalidArgumentException('Payout requires account or account_reference.');
        }
        return $this->http->request('POST', '/v2/payouts', body: $payload, idempotencyKey: $idempotencyKey);
    }

    /** @param array<string, mixed> $payload */
    public function bulk(array $payload, string $idempotencyKey): ApiResponse
    {
        if (empty($payload['items']) || !is_array($payload['items']) || !array_is_list($payload['items'])) {
            throw new \InvalidArgumentException('Bulk payout requires a nonempty items list.');
        }
        if (isset($payload['currency'])) {
            Validate::currency($payload['currency']);
        }
        foreach ($payload['items'] as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Each payout item must be an object.');
            }
            Validate::money($item, requiresCurrency: !isset($payload['currency']));
            if (!isset($item['account']) && !isset($item['account_reference'])) {
                throw new \InvalidArgumentException('Each payout item requires an account or account_reference.');
            }
        }
        return $this->http->request('POST', '/v2/payouts/bulk', body: $payload, idempotencyKey: $idempotencyKey);
    }

    /** @param array<string, mixed> $payload */
    public function b2b(array $payload, string $idempotencyKey): ApiResponse
    {
        Validate::money($payload, requiresCurrency: false);
        Validate::nonEmpty($payload, 'merchant_id');
        return $this->http->request('POST', '/v2/payouts/b2b', body: $payload, idempotencyKey: $idempotencyKey);
    }

    public function verify(string $reference): ApiResponse
    {
        return $this->http->request('GET', '/v2/payouts/'.Validate::reference($reference).'/verify');
    }

    public function banks(?string $country = null): ApiResponse
    {
        return $this->http->request('GET', '/v2/payouts/banks', query: $country === null ? [] : ['country' => $country]);
    }

    /** @param array<string, mixed> $query */
    public function list(array $query = []): ApiResponse
    {
        Validate::limit($query);
        return $this->http->request('GET', '/v2/payouts', query: $query);
    }
}
