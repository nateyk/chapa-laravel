<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Webhooks;

/** Parsed webhook notification; NOT evidence of payment or payout settlement. */
final readonly class WebhookEvent
{
    /** @var array<string, array<string, string>> Documented v2 event => status contract, by webhook type. */
    private const EVENTS = [
        'payment' => [
            'payment.success' => 'success',
            'payment.failed' => 'failed',
            'payment.cancelled' => 'cancelled',
            'payment.incomplete' => 'incomplete',
            'payment.auth_needed' => 'auth_needed',
            'payment.blocked' => 'blocked',
        ],
        'payout' => [
            'payout.success' => 'success',
            'payout.failed' => 'failed',
            'payout.reversed' => 'reversed',
            'payout.blocked' => 'blocked',
            'payout.auth_needed' => 'auth_needed',
            'payout.otp_needed' => 'otp_needed',
            'payout.otp_failed' => 'otp_failed',
        ],
        'refund' => [
            'payment.partially_refunded' => 'partially_refunded',
            'payment.fully_refunded' => 'fully_refunded',
        ],
    ];

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
        if (!is_string($type) || !is_string($event) || !is_string($status)
            || !isset(self::EVENTS[$type][$event]) || self::EVENTS[$type][$event] !== $status) {
            throw new \UnexpectedValueException('Unsupported Chapa v2 webhook type, event, or status combination.');
        }
        if (isset($data['mode']) && !in_array($data['mode'], ['live', 'test'], true)) {
            throw new \UnexpectedValueException('Chapa webhook mode is invalid.');
        }
        foreach (['chapa_reference', 'merchant_reference', 'currency', 'updated_at'] as $field) {
            if (isset($data[$field]) && (!is_string($data[$field]) || trim($data[$field]) === '')) {
                throw new \UnexpectedValueException('Invalid webhook field: '.$field);
            }
        }
        if (isset($data['currency']) && preg_match('/^[A-Z]{3}$/D', $data['currency']) !== 1) {
            throw new \UnexpectedValueException('Webhook currency must be three uppercase letters.');
        }
        $amount = self::cents($data['amount'] ?? null);
        $refundedAmount = self::cents($data['refunded_amount'] ?? null);
        if ($type === 'refund' && $amount !== null && $refundedAmount !== null && $refundedAmount > $amount) {
            throw new \UnexpectedValueException('Refunded amount cannot exceed original amount.');
        }
        return new self(
            $type, $event, $status,
            $data['mode'] ?? null,
            $data['chapa_reference'] ?? null,
            $data['merchant_reference'] ?? null,
            $amount,
            $refundedAmount,
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
            if (strlen($value) < strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) <= 0)) {
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
