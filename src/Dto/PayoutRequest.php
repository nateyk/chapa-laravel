<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Dto;

use Nateyk\Chapa\Contracts\RequestPayload;
use Nateyk\Chapa\Support\RequestValidator;

final readonly class PayoutRequest implements RequestPayload
{
    /** @param array<string, mixed>|null $account @param array<string, mixed> $meta */
    public function __construct(
        public int $amount,
        public ?string $currency = null,
        public ?array $account = null,
        public ?string $accountReference = null,
        public ?string $merchantReference = null,
        public array $meta = [],
    ) {
        if ($amount < 100) {
            throw new \InvalidArgumentException('Payout amount must be at least 100 cents.');
        }
        if ($currency !== null) {
            RequestValidator::currency($currency);
        }
        if ($merchantReference !== null) {
            RequestValidator::merchantReference(['merchant_reference' => $merchantReference]);
        }
        if (($account === null || $account === []) && ($accountReference === null || trim($accountReference) === '')) {
            throw new \InvalidArgumentException('Account or accountReference is required.');
        }
    }

    public function toArray(): array
    {
        return RequestFields::stripNullAndEmptyArrays([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'account' => $this->account,
            'account_reference' => $this->accountReference,
            'merchant_reference' => $this->merchantReference,
            'meta' => $this->meta,
        ]);
    }
}
