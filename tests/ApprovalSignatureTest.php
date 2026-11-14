<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Nateyk\Chapa\Transfers\ApprovalCallback;
use Nateyk\Chapa\Transfers\ApprovalSignature;
use PHPUnit\Framework\TestCase;

final class ApprovalSignatureTest extends TestCase
{
    private const SECRET = 'test-only-very-long-approval-secret';

    public function testLegacyDecimalAmountBecomesIntegerCentsWithoutFloats(): void
    {
        $body = '{"reference":"pay-123","amount":"2000.05","bank":"telebirr","account_name":"Abebe","account_number":"25190000000"}';
        $payload = ApprovalCallback::fromRawBody($body);
        self::assertSame(200005, $payload->amountCents);
        self::assertSame('pay-123', $payload->reference);
        self::assertNull($payload->currency);
    }

    public function testValidHmacRequiresExactOriginalBody(): void
    {
        $body = '{"reference":"pay-123","amount":"100.00","bank":"telebirr","account_name":"Abebe","account_number":"25190000000"}';
        $signature = hash_hmac('sha256', $body, self::SECRET);
        $verifier = new ApprovalSignature(self::SECRET);
        self::assertTrue($verifier->verify($body, strtoupper($signature)));
        self::assertFalse($verifier->verify($body.' ', $signature));
        self::assertFalse($verifier->verify($body, ''));
        self::assertFalse($verifier->verify($body, '9f6a8c3b24d7e1f5a4c2b7a6e8f1c3d4'));
    }

    public function testInvalidDecimalAndMissingFieldsFailClosed(): void
    {
        $base = ['reference' => 'pay-123', 'amount' => '100.00', 'bank' => 'telebirr',
            'account_name' => 'Abebe', 'account_number' => '0012345'];
        foreach (['100.005', '-10.00', '1e3', '0.01', '99999999999999.99', 100.00, ' 100.00'] as $amount) {
            try {
                ApprovalCallback::fromRawBody(json_encode(array_replace($base, ['amount' => $amount]), JSON_THROW_ON_ERROR));
                self::fail('Invalid amount accepted: '.get_debug_type($amount));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach (['reference', 'bank', 'account_name', 'account_number'] as $missing) {
            $payload = $base;
            unset($payload[$missing]);
            try {
                ApprovalCallback::fromRawBody(json_encode($payload, JSON_THROW_ON_ERROR));
                self::fail('Missing field accepted: '.$missing);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
