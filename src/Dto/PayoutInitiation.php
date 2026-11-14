<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Dto;

use Nateyk\Chapa\Http\ApiResponse;

/** Typed payout creation receipt; never proof that funds were delivered. */
final readonly class PayoutInitiation
{
    private function __construct(
        public string $chapaReference,
        public PayoutStatus $status,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    public static function fromResponse(ApiResponse $response): self
    {
        $data = $response->data();
        if (!$response->operationSucceeded() || !is_array($data) || array_is_list($data)
            || !is_string($data['chapa_reference'] ?? null) || trim($data['chapa_reference']) === ''
            || !is_string($data['status'] ?? null)) {
            throw new \UnexpectedValueException('Chapa payout creation did not contain a valid reference and status.');
        }

        $status = PayoutStatus::tryFrom($data['status']);
        if ($status === null) {
            throw new \UnexpectedValueException('Chapa payout creation returned an unknown status.');
        }
        foreach (['created_at', 'updated_at'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && !is_string($data[$field])) {
                throw new \UnexpectedValueException('Chapa payout creation returned an invalid timestamp.');
            }
        }

        return new self($data['chapa_reference'], $status, $data['created_at'] ?? null, $data['updated_at'] ?? null);
    }

    /** No API creation response alone establishes settlement. */
    public function delivered(): bool
    {
        return false;
    }
}
