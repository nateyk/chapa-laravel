<?php

declare(strict_types=1);

namespace Nateyk\Chapa;

use Nateyk\Chapa\Http\HttpClient;
use Nateyk\Chapa\Http\Transport;
use Nateyk\Chapa\Resources\Balances;
use Nateyk\Chapa\Resources\Payments;
use Nateyk\Chapa\Resources\Payouts;
use Nateyk\Chapa\Resources\Refunds;
use Nateyk\Chapa\Resources\Subaccounts;
use Nateyk\Chapa\Support\WebhookVerifier;

/** Framework-independent Chapa v2 SDK. No network calls occur during construction. */
final class ChapaClient
{
    private readonly HttpClient $http;

    public function __construct(
        string $privateKey,
        ?string $publicKey = null,
        private readonly ?string $webhookSecret = null,
        ?Transport $transport = null,
        string $baseUrl = 'https://api.chapa.global',
        int $timeout = 15,
    ) {
        $this->http = new HttpClient($privateKey, $publicKey, $transport, $baseUrl, $timeout);
    }

    public function payments(): Payments
    {
        return new Payments($this->http);
    }

    public function payouts(): Payouts
    {
        return new Payouts($this->http);
    }

    public function refunds(): Refunds
    {
        return new Refunds($this->http);
    }

    public function subaccounts(): Subaccounts
    {
        return new Subaccounts($this->http);
    }

    public function balances(): Balances
    {
        return new Balances($this->http);
    }

    public function webhooks(): WebhookVerifier
    {
        return new WebhookVerifier($this->webhookSecret ?? '');
    }
}
