<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Nateyk\Chapa\Transfers\ApprovalDiagnostic;
use PHPUnit\Framework\TestCase;

final class ApprovalDiagnosticTest extends TestCase
{
    private const SECRET = 'a-long-test-approval-secret-for-diagnostics';
    private const BODY = '{"amount":"100.00","reference":"TESTPAY1","bank":"telebirr","account_name":"Test Person","account_number":"00123456"}';

    public function testSignedBodyOnlyReturnsFixedStatuses(): void
    {
        $result = ApprovalDiagnostic::inspect(self::BODY, hash_hmac('sha256', self::BODY, self::SECRET), self::SECRET);
        self::assertSame(['signature' => 'matched_raw_body_hmac', 'payload' => 'valid'], $result);
        self::assertStringNotContainsString('00123456', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::SECRET, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('TESTPAY1', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testTamperingMissingSignatureAndUnavailableSecretFailClosed(): void
    {
        $sig = hash_hmac('sha256', self::BODY, self::SECRET);
        self::assertSame('mismatch', ApprovalDiagnostic::inspect(self::BODY.' ', $sig, self::SECRET)['signature']);
        self::assertSame('mismatch', ApprovalDiagnostic::inspect(self::BODY, null, self::SECRET)['signature']);
        self::assertSame('unconfigured', ApprovalDiagnostic::inspect(self::BODY, $sig, null)['payload']);
        self::assertSame('rejected_size', ApprovalDiagnostic::inspect(str_repeat('a', 17000), $sig, self::SECRET)['payload']);
    }

    public function testValidHmacDoesNotOverridePayloadValidation(): void
    {
        $bad = '{"amount":"100.00","reference":"TESTPAY1"}';
        $result = ApprovalDiagnostic::inspect($bad, hash_hmac('sha256', $bad, self::SECRET), self::SECRET);
        self::assertSame('matched_raw_body_hmac', $result['signature']);
        self::assertSame('invalid', $result['payload']);
    }
}
