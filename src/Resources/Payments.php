<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Resources;

use Nateyk\Chapa\Http\ApiResponse;
use Nateyk\Chapa\Dto\HostedCheckoutRequest;
use Nateyk\Chapa\Dto\DirectChargeRequest;
use Nateyk\Chapa\Dto\InlineChargeRequest;
use Nateyk\Chapa\Dto\VerifiedTransaction;
use Nateyk\Chapa\Http\HttpClient;
use Nateyk\Chapa\Support\RequestValidator as Validate;

final class Payments
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /** @param array<string, mixed> $payload */
    public function hosted(array|HostedCheckoutRequest $payload, string $idempotencyKey): ApiResponse
    {
        $payload = $payload instanceof HostedCheckoutRequest ? $payload->toArray() : $payload;
        Validate::money($payload);
        return $this->http->request('POST', '/v2/payments/hosted', body: $payload, idempotencyKey: $idempotencyKey);
    }

    /** @param array<string, mixed> $payload */
    public function direct(array|DirectChargeRequest $payload, string $idempotencyKey): ApiResponse
    {
        $payload = $payload instanceof DirectChargeRequest ? $payload->toArray() : $payload;
        Validate::money($payload);
        Validate::nonEmpty($payload, 'payment_method');
        if (!isset($payload['customer']) || !is_array($payload['customer'])) {
            throw new \InvalidArgumentException('Direct charge requires a customer object.');
        }
        Validate::nonEmpty($payload['customer'], 'phone_number');
        return $this->http->request('POST', '/v2/payments/direct', body: $payload, idempotencyKey: $idempotencyKey);
    }

    /** @param array<string, mixed> $payload */
    public function validateDirect(array $payload): ApiResponse
    {
        Validate::nonEmpty($payload, 'reference');
        Validate::nonEmpty($payload, 'payment_method');
        return $this->http->request('POST', '/v2/payments/direct/validate', body: $payload);
    }

    /** This public-key operation is meant for a trusted backend call; never proxy an arbitrary browser payload. */
    public function inline(array|InlineChargeRequest $payload, string $idempotencyKey): ApiResponse
    {
        $payload = $payload instanceof InlineChargeRequest ? $payload->toArray() : $payload;
        Validate::money($payload);
        Validate::nonEmpty($payload, 'payment_method');
        Validate::nonEmpty($payload, 'mobile');
        return $this->http->request('POST', '/v2/payments/inline', body: $payload, usePublicKey: true, idempotencyKey: $idempotencyKey);
    }

    /** @param array<string, mixed> $payload */
    public function validateInline(array $payload): ApiResponse
    {
        Validate::nonEmpty($payload, 'reference');
        Validate::nonEmpty($payload, 'payment_method');
        return $this->http->request('POST', '/v2/payments/inline/validate', body: $payload, usePublicKey: true);
    }

    public function verify(string $reference): ApiResponse
    {
        return $this->http->request('GET', '/v2/payments/'.Validate::reference($reference).'/verify');
    }

    /** Verified, typed payment details; never infer settlement from payment creation. */
    public function verifyTyped(string $reference): VerifiedTransaction
    {
        return VerifiedTransaction::fromResponse($this->verify($reference), 'payment');
    }

    public function methods(): ApiResponse
    {
        return $this->http->request('GET', '/v2/payments/methods');
    }

    /** @param array<string, mixed> $query API documents only `limit`; other filters intentionally pass through. */
    public function list(array $query = []): ApiResponse
    {
        Validate::limit($query);
        return $this->http->request('GET', '/v2/payments', query: $query);
    }

    public function htmlStatus(): ApiResponse
    {
        return $this->http->request('GET', '/v2/payments/html', usePublicKey: true);
    }
}
