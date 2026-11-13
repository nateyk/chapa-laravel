<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Dto;

use Nateyk\Chapa\Contracts\RequestPayload;

/** Chapa v2 hosted checkout; amount is in smallest currency units (cents). */
final readonly class HostedCheckoutRequest implements RequestPayload
{
    /**
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $meta
     * @param list<array<string, mixed>> $subaccounts
     * @param array<string, mixed> $customization
     * @param list<string> $preferredPaymentMethods
     */
    public function __construct(
        public int $amount,
        public string $currency,
        public ?string $merchantReference = null,
        public array $customer = [],
        public ?string $callbackUrl = null,
        public ?string $returnUrl = null,
        public array $meta = [],
        public array $subaccounts = [],
        public array $customization = [],
        public array $preferredPaymentMethods = [],
    ) {
        RequestFields::validateMoney($amount, $currency, $merchantReference);
        RequestFields::httpsUrl($callbackUrl, 'callbackUrl');
        RequestFields::httpsUrl($returnUrl, 'returnUrl');
    }

    public function toArray(): array
    {
        return RequestFields::stripNullAndEmptyArrays([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'merchant_reference' => $this->merchantReference,
            'customer' => $this->customer,
            'callback_url' => $this->callbackUrl,
            'return_url' => $this->returnUrl,
            'meta' => $this->meta,
            'subaccounts' => $this->subaccounts,
            'customization' => $this->customization,
            'preferred_payment_methods' => $this->preferredPaymentMethods,
        ]);
    }
}
