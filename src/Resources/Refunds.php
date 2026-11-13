<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Resources;

use Nateyk\Chapa\Http\ApiResponse;
use Nateyk\Chapa\Dto\RefundRequest;
use Nateyk\Chapa\Dto\VerifiedTransaction;
use Nateyk\Chapa\Http\HttpClient;
use Nateyk\Chapa\Support\RequestValidator as Validate;

final class Refunds
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /** @param array<string, mixed> $payload Omit amount to refund the entire payment. */
    public function create(array|RefundRequest $payload, string $idempotencyKey): ApiResponse
    {
        $payload = $payload instanceof RefundRequest ? $payload->toArray() : $payload;
        Validate::nonEmpty($payload, 'payment_reference');
        Validate::merchantReference($payload);
        if (array_key_exists('amount', $payload)
            && (!is_int($payload['amount']) || $payload['amount'] < 100)) {
            throw new \InvalidArgumentException('Partial refund amount must be integer cents of at least 100.');
        }
        if (isset($payload['reason']) && (!is_string($payload['reason']) || strlen($payload['reason']) > 400)) {
            throw new \InvalidArgumentException('Refund reason must be at most 400 characters.');
        }
        return $this->http->request('POST', '/v2/refunds', body: $payload, idempotencyKey: $idempotencyKey);
    }

    public function verify(string $reference): ApiResponse
    {
        return $this->http->request('GET', '/v2/refunds/'.Validate::reference($reference).'/verify');
    }

    /** Refund verification requires the refund reference, not the original payment reference. */
    public function verifyTyped(string $reference): VerifiedTransaction
    {
        return VerifiedTransaction::fromResponse($this->verify($reference), 'refund');
    }

    /** @param array<string, mixed> $query */
    public function list(array $query = []): ApiResponse
    {
        Validate::limit($query);
        return $this->http->request('GET', '/v2/refunds', query: $query);
    }
}
