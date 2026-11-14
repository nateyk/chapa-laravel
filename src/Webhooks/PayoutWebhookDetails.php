<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Webhooks;

/**
 * Validated optional payout webhook metadata. Never exposes the recipient's
 * full account number. Callback status alone cannot establish settlement.
 */
final readonly class PayoutWebhookDetails
{
    /**
     * @param ?list<string> $otpChannels
     */
    private function __construct(
        public ?string $payoutType,
        public ?int $serviceFeeCents,
        public ?string $processorReference,
        public ?string $reason,
        public ?string $authType,
        public ?array $otpChannels,
        public ?int $otpAttempts,
        public ?string $bankSlug,
        public ?string $accountLastFour,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        if (($payload['webhook_type'] ?? null) !== 'payout') {
            throw new \InvalidArgumentException('Only payout notifications have payout details.');
        }
        $type = self::field($payload, 'payout_type');
        if ($type !== null && !in_array($type, ['customer_payout','merchant_payout','bulk_payout'], true)) {
            throw new \UnexpectedValueException('Unknown payout_type.');
        }
        $fee = null;
        if (array_key_exists('service_fee', $payload)) {
            $fee = self::integerCents($payload['service_fee']);
        }
        $processor = self::field($payload, 'processor_reference');
        $reason = self::field($payload, 'reason');
        $auth = self::field($payload, 'auth_type');
        $otpAttempts = $payload['otp_attempts'] ?? null;
        if (array_key_exists('otp_attempts', $payload)
            && (!is_int($otpAttempts) || $otpAttempts < 0 || $otpAttempts > 1000)) {
            throw new \UnexpectedValueException('Invalid OTP attempt count.');
        }
        $channels = null;
        if (array_key_exists('otp_channel', $payload)) {
            $channels = $payload['otp_channel'];
            if (!is_array($channels) || !array_is_list($channels) || count($channels) > 16) {
                throw new \UnexpectedValueException('OTP channels must be a list.');
            }
            foreach ($channels as $channel) {
                if (!is_string($channel) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $channel) !== 1) {
                    throw new \UnexpectedValueException('Invalid OTP channel.');
                }
            }
        }
        $bank = null;
        $lastFour = null;
        if (array_key_exists('account', $payload)) {
            $account = $payload['account'];
            if (!is_array($account) || array_is_list($account)) {
                throw new \UnexpectedValueException('Payout account must be an object.');
            }
            foreach (['account_name','account_number','bank_slug','bank_name'] as $key) {
                self::field($account, $key);
            }
            $bank = $account['bank_slug'] ?? null;
            $number = $account['account_number'] ?? null;
            $lastFour = is_string($number) ? substr($number, -4) : null;
        }

        return new self($type, $fee, $processor, $reason, $auth, $channels,
            $otpAttempts, $bank, $lastFour);
    }

    /** @param array<string,mixed> $values */
    private static function field(array $values, string $name): ?string
    {
        if (!array_key_exists($name, $values)) {
            return null;
        }
        $value = $values[$name];
        if (!is_string($value) || trim($value) === '' || strlen($value) > 190
            || strpbrk($value, "\r\n\0") !== false) {
            throw new \UnexpectedValueException('Invalid payout metadata field: '.$name);
        }
        return $value;
    }

    private static function integerCents(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $max = (string) PHP_INT_MAX;
            if (strlen($value) < strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) <= 0)) {
                return (int) $value;
            }
        }
        throw new \UnexpectedValueException('Payout fee must be nonnegative integer cents.');
    }
}
