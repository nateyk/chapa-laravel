<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Dto;

use Nateyk\Chapa\Contracts\RequestPayload;

final readonly class DirectChargeRequest implements RequestPayload
{
    /** @param array<string, mixed> $customer @param array<string, mixed> $metadata */
    public function __construct(
        public int $amount,
        public string $currency,
        public string $paymentMethod,
        public string $phoneNumber,
        public ?string $merchantReference = null,
        public array $customer = [],
        public ?string $callbackUrl = null,
        public ?string $returnUrl = null,
        public array $metadata = [],
    ) {
        RequestFields::validateMoney($amount, $currency, $merchantReference);
        if (trim($paymentMethod) === '' || trim($phoneNumber) === '') {
            throw new \InvalidArgumentException('Payment method and customer phone number are required.');
        }
        RequestFields::httpsUrl($callbackUrl, 'callbackUrl');
        RequestFields::httpsUrl($returnUrl, 'returnUrl');
    }

    public function toArray(): array
    {
        return RequestFields::stripNullAndEmptyArrays([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payment_method' => $this->paymentMethod,
            'merchant_reference' => $this->merchantReference,
            // Dedicated phone argument cannot be overwritten by extra customer fields.
            'customer' => ['phone_number' => $this->phoneNumber] + $this->customer,
            'callback_url' => $this->callbackUrl,
            'return_url' => $this->returnUrl,
            'metadata' => $this->metadata,
        ]);
    }
}
