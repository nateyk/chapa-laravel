<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Webhooks;

/** Normalized Chapa v2 event data. This object is not proof of payment settlement. */
final readonly class WebhookEvent
{
    /** @param array<string, mixed> $payload */
    private function __construct(
        public string $type,
        public string $name,
        public string $status,
        public ?string $mode,
        public ?string $chapaReference,
        public ?string $merchantReference,
        public ?int $amount,
        public ?int $refundedAmount,
        public ?string $currency,
        public ?string $updatedAt,
        public array $payload,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = $data['webhook_type'] ?? null;
        $event = $data['event'] ?? null;
        $status = $data['status'] ?? null;
        if (!is_string($type) || !in_array($type, ['payment', 'payout', 'refund'], true)
            || !is_string($event) || strlen($event) > 128 || trim($event) === ''
            || !is_string($status) || strlen($status) > 64 || trim($status) === '') {
            throw new \UnexpectedValueException('Chapa webhook type, event, or status is invalid.');
        }
        if (isset($data['mode']) && !in_array($data['mode'], ['live', 'test'], true)) {
            throw new \UnexpectedValueException('Chapa webhook mode is invalid.');
        }
        // Refunds use webhook_type=refund but event=payment.partially_refunded/fully_refunded.
        if (($type === 'payment' && !str_starts_with($event, 'payment.'))
            || ($type === 'payout' && !str_starts_with($event, 'payout.'))
            || ($type === 'refund' && !in_array($event, ['payment.partially_refunded', 'payment.fully_refunded'], true))) {
            throw new \UnexpectedValueException('Chapa webhook event does not match its type.');
        }
        foreach (['chapa_reference', 'merchant_reference', 'currency', 'updated_at'] as $field) {
            if (isset($data[$field]) && (!is_string($data[$field]) || trim($data[$field]) === '')) {
                throw new \UnexpectedValueException('Invalid webhook field: '.$field);
            }
        }
        if (isset($data['currency']) && preg_match('/^[A-Z]{3}$/D', $data['currency']) !== 1) {
            throw new \UnexpectedValueException('Webhook currency must be three uppercase letters.');
        }
        return new self(
            $type, $event, $status,
            $data['mode'] ?? null,
            $data['chapa_reference'] ?? null,
            $data['merchant_reference'] ?? null,
            self::cents($data['amount'] ?? null),
            self::cents($data['refunded_amount'] ?? null),
            $data['currency'] ?? null,
            $data['updated_at'] ?? null,
            $data,
        );
    }

    private static function cents(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $max = (string) PHP_INT_MAX;
            if (strlen($value) <= strlen($max) && (strlen($value) < strlen($max) || strcmp($value, $max) <= 0)) {
                return (int) $value;
            }
        }
        throw new \UnexpectedValueException('Webhook money field must be a nonnegative integer in cents.');
    }

    public function signalsSuccessfulPayment(): bool
    {
        return $this->type === 'payment' && $this->name === 'payment.success' && $this->status === 'success';
    }
}
