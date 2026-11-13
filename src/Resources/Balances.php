<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Resources;

use Nateyk\Chapa\Http\ApiResponse;
use Nateyk\Chapa\Http\HttpClient;
use Nateyk\Chapa\Support\RequestValidator as Validate;

final class Balances
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    public function get(?string $currency = null): ApiResponse
    {
        if ($currency !== null) {
            Validate::currency($currency);
        }
        return $this->http->request('GET', '/v2/balances', query: $currency === null ? [] : ['currency' => $currency]);
    }
}
