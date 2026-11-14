<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Transfers;

/** The legacy Chapa Transfer Approval URL POST payload, not a v2 payout webhook. */
final readonly class ApprovalCallback
{
    private function __construct(
        public string $reference,
        public int $amountCents,
        public string $bank,
        public string $accountName,
        public string $accountNumber,
        public ?string $currency,
    ) {
    }

    public static function fromRawBody(string $body): self
    {
        if ($body === '' || strlen($body) > 16384) {
            throw new \InvalidArgumentException('Invalid transfer approval payload size.');
        }
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Invalid transfer approval JSON.', previous: $exception);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('Transfer approval must be a JSON object.');
        }
        foreach (['reference', 'bank', 'account_name', 'account_number'] as $field) {
            if (!is_string($data[$field] ?? null) || trim($data[$field]) === ''
                || strlen($data[$field]) > 190 || strpbrk($data[$field], "\r\n\0") !== false) {
                throw new \InvalidArgumentException('Invalid transfer approval field.');
            }
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $data['reference']) !== 1) {
            throw new \InvalidArgumentException('Invalid merchant transfer reference.');
        }
        // The v1 approval callback uses major-unit decimal strings, while v2 payouts use integer cents.
        $amount = $data['amount'] ?? null;
        if (!is_string($amount) || preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/D', $amount, $match) !== 1) {
            throw new \InvalidArgumentException('Approval amount must be a decimal major-unit string.');
        }
        $whole = (int) $match[1];
        if ($whole > intdiv(PHP_INT_MAX, 100)) {
            throw new \InvalidArgumentException('Approval amount is out of range.');
        }
        $fraction = (int) str_pad($match[2] ?? '', 2, '0');
        $cents = $whole * 100 + $fraction;
        if ($cents < 100) {
            throw new \InvalidArgumentException('Approval transfer amount is below minimum.');
        }
        $currency = $data['currency'] ?? null;
        if ($currency !== null && (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1)) {
            throw new \InvalidArgumentException('Invalid transfer approval currency.');
        }
        return new self(
            $data['reference'], $cents, trim($data['bank']),
            trim($data['account_name']), trim($data['account_number']), $currency,
        );
    }
}
