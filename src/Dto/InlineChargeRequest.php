<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Dto;

use Nateyk\Chapa\Contracts\RequestPayload;

final readonly class InlineChargeRequest implements RequestPayload
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public int $amount,
        public string $currency,
        public string $paymentMethod,
        public string $mobile,
        public ?string $merchantReference = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $email = null,
        public ?string $callbackUrl = null,
        public ?string $returnUrl = null,
        public array $meta = [],
    ) {
        RequestFields::validateMoney($amount, $currency, $merchantReference);
        if (trim($paymentMethod) === '' || trim($mobile) === '') {
            throw new \InvalidArgumentException('Payment method and mobile number are required.');
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
            'mobile' => $this->mobile,
            'merchant_reference' => $this->merchantReference,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'callback_url' => $this->callbackUrl,
            'return_url' => $this->returnUrl,
            'meta' => $this->meta,
        ]);
    }
}
