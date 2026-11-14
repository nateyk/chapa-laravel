<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Dto\PayoutRequest;
use Nateyk\Chapa\Dto\PayoutStatus;
use Nateyk\Chapa\Dto\VerifiedTransaction;
use Nateyk\Chapa\Http\ApiResponse;
use Nateyk\Chapa\Http\RawResponse;
use PHPUnit\Framework\TestCase;

final class PayoutLifecycleTest extends TestCase
{
    private function verified(string $status): VerifiedTransaction
    {
        return VerifiedTransaction::fromResponse(new ApiResponse(200, [
            'status' => 'success',
            'data' => [
                'chapa_reference' => 'CP_T_1234',
                'merchant_reference' => 'payout-101',
                'amount' => 10000,
                'currency' => 'ETB',
                'status' => $status,
            ],
        ]), 'payout');
    }

    public function testEveryDocumentedPayoutStatusAndActionNeeded(): void
    {
        foreach (PayoutStatus::cases() as $status) {
            $verified = $this->verified($status->value);
            self::assertSame($status, $verified->payoutStatus());
            self::assertSame($status->requiresAction(), $verified->payoutRequiresAction());
            self::assertSame($status === PayoutStatus::SUCCESS, $verified->matchesPayout(10000, 'ETB', 'payout-101'));
        }
        self::assertTrue($this->verified('AUTH_NEEDED')->payoutRequiresAction());
        self::assertTrue($this->verified('OTP_NEEDED')->payoutRequiresAction());
    }

    public function testPayoutVerificationMatchesAllDimensions(): void
    {
        $verified = $this->verified('SUCCESS');
        self::assertTrue($verified->matchesPayout(10000, 'ETB', 'payout-101'));
        self::assertFalse($verified->matchesPayout(10001, 'ETB', 'payout-101'));
        self::assertFalse($verified->matchesPayout(10000, 'USD', 'payout-101'));
        self::assertFalse($verified->matchesPayout(10000, 'ETB', 'wrong'));
        self::assertFalse($this->verified('REVERSED')->matchesPayout(10000, 'ETB', 'payout-101'));
    }

    public function testCreationReturnsPendingReceiptAndPreservesOptionalFields(): void
    {
        $transport = new MockTransport();
        $transport->responses[] = new RawResponse(200, '{"status":"success","data":{"chapa_reference":"CP_T_1234","status":"PENDING","created_at":"2026-10-05T09:30:00Z"}}');
        $chapa = new ChapaClient('private', transport: $transport);
        $created = $chapa->payouts()->createTyped(new PayoutRequest(
            amount: 10000,
            currency: 'ETB',
            account: ['account_number' => '251900000000', 'account_name' => 'Synthetic User', 'bank_slug' => 'telebirr'],
            merchantReference: 'payout-101',
            meta: ['batch' => 'TEST'],
            reason: 'Vendor payout',
        ), 'payout-operation-101');
        self::assertSame(PayoutStatus::PENDING, $created->status);
        self::assertFalse($created->delivered());
        self::assertSame('CP_T_1234', $created->chapaReference);
        $request = $transport->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('payout-operation-101', $request['headers']['Idempotency-Key']);
        $body = json_decode((string) $request['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Vendor payout', $body['reason']);
        self::assertSame(['batch' => 'TEST'], $body['meta']);
    }

    public function testAccountReferenceAndInlineAccountCanCoexist(): void
    {
        $transport = new MockTransport();
        $chapa = new ChapaClient('private', transport: $transport);
        $chapa->payouts()->create(['amount' => 10000, 'account_reference' => 'SAVED_1'], 'saved-op');
        $chapa->payouts()->create([
            'amount' => 10000, 'account_reference' => 'SAVED_1',
            'account' => ['account_number' => '251900000000', 'account_name' => 'Synthetic User', 'bank_slug' => 'telebirr'],
        ], 'inline-op');
        self::assertCount(2, $transport->requests);
    }

    public function testMalformedPayoutDestinationsFailBeforeNetwork(): void
    {
        $transport = new MockTransport();
        $chapa = new ChapaClient('private', transport: $transport);
        foreach ([
            ['amount' => 10000],
            ['amount' => 10000, 'account' => []],
            ['amount' => 10000, 'account' => ['account_number' => '251900000000']],
            ['amount' => 10000, 'account' => ['account_number' => '251900000000', 'account_name' => '', 'bank_slug' => 'telebirr']],
            ['amount' => 10000, 'account_reference' => '  '],
            ['amount' => 10000, 'account_reference' => 10],
            ['amount' => 10000, 'account_reference' => 'SAVED_1', 'account' => ['bank_slug' => 'telebirr']],
            ['amount' => 10000, 'account_reference' => 'SAVED_1', 'meta' => ['invalid-list']],
            ['amount' => 10000, 'account_reference' => 'SAVED_1', 'reason' => 100],
        ] as $payload) {
            try {
                $chapa->payouts()->create($payload, 'unique-attempt');
                self::fail('Invalid destination accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertCount(0, $transport->requests);
    }

    public function testBulkPayoutRejectsIncompleteAccount(): void
    {
        $transport = new MockTransport();
        $chapa = new ChapaClient('private', transport: $transport);
        $this->expectException(\InvalidArgumentException::class);
        $chapa->payouts()->bulk([
            'currency' => 'ETB', 'items' => [
                ['amount' => 10000, 'account' => ['bank_slug' => 'telebirr']],
            ],
        ], 'batch-key');
    }

    public function testUnknownPayoutStatusFailsClosed(): void
    {
        $verified = $this->verified('UNDOCUMENTED');
        self::assertNull($verified->payoutStatus());
        self::assertFalse($verified->payoutRequiresAction());
        self::assertFalse($verified->matchesPayout(10000, 'ETB', 'payout-101'));
    }
}
