<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Dto;

use Nateyk\Chapa\Http\ApiResponse;

/** Typed read-only view of Chapa verification data, never of a webhook notification. */
final readonly class VerifiedTransaction
{
    /** @param array<string, mixed> $raw */
    private function __construct(
        public string $kind,
        public string $status,
        public string $chapaReference,
        public ?string $merchantReference,
        public ?string $paymentReference,
        public int $amount,
        public string $currency,
        public array $raw,
    ) {
    }

    public static function fromResponse(ApiResponse $response, string $kind): self
    {
        if (!in_array($kind, ['payment', 'payout', 'refund'], true)) {
            throw new \InvalidArgumentException('Unknown verification type.');
        }
        $data = $response->data();
        if (!$response->operationSucceeded() || !is_array($data)
            || !is_string($data['status'] ?? null)
            || !is_string($data['chapa_reference'] ?? null)
            || !is_int($data['amount'] ?? null)
            || !is_string($data['currency'] ?? null)) {
            throw new \UnexpectedValueException('Chapa verification did not contain the documented transaction fields.');
        }
        return new self(
            $kind,
            $data['status'],
            $data['chapa_reference'],
            is_string($data['merchant_reference'] ?? null) ? $data['merchant_reference'] : null,
            is_string($data['payment_reference'] ?? null) ? $data['payment_reference'] : null,
            $data['amount'],
            $data['currency'],
            $data,
        );
    }

    public function succeeded(): bool
    {
        // Refund status semantics may evolve. Only the documented payout/payment completion status is recognized.
        return $this->kind !== 'refund' && $this->status === 'SUCCESS';
    }

    public function matchesPayment(int $amount, string $currency, string $merchantReference): bool
    {
        return $this->kind === 'payment'
            && $this->succeeded()
            && $this->amount === $amount
            && $this->currency === $currency
            && $this->merchantReference === $merchantReference;
    }
}
