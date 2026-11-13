<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Dto;

use Nateyk\Chapa\Contracts\RequestPayload;

final readonly class RefundRequest implements RequestPayload
{
    public function __construct(
        public string $paymentReference,
        public ?int $amount = null,
        public ?string $reason = null,
        public ?string $merchantReference = null,
    ) {
        if (trim($paymentReference) === '') {
            throw new \InvalidArgumentException('paymentReference is required.');
        }
        if ($amount !== null && $amount < 100) {
            throw new \InvalidArgumentException('Partial refund must be at least 100 cents.');
        }
        if ($reason !== null && strlen($reason) > 400) {
            throw new \InvalidArgumentException('Refund reason must be at most 400 characters.');
        }
    }

    public function toArray(): array
    {
        return RequestFields::stripNullAndEmptyArrays([
            'payment_reference' => $this->paymentReference,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'merchant_reference' => $this->merchantReference,
        ]);
    }
}
