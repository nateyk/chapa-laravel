<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Nateyk\Chapa\Dto\VerifiedTransaction;
use Nateyk\Chapa\Http\ApiResponse;
use Nateyk\Chapa\Laravel\Events\ChapaWebhookProcessed;
use Nateyk\Chapa\Webhooks\WebhookConsistency;
use Nateyk\Chapa\Webhooks\WebhookEvent;
use Nateyk\Chapa\Webhooks\WebhookStateGuard;
use PHPUnit\Framework\TestCase;

final class PayoutWebhookMetadataTest extends TestCase
{
    /** @return array<string,mixed> */
    private function payload(string $event = 'payout.otp_needed', string $status = 'otp_needed'): array
    {
        return [
            'webhook_type' => 'payout', 'event' => $event, 'status' => $status,
            'payout_type' => 'customer_payout', 'currency' => 'ETB', 'amount' => '200000',
            'service_fee' => '6000', 'merchant_reference' => 'PAYOUT123OTP',
            'chapa_reference' => 'CHP123OTP', 'processor_reference' => 'BANKREF123',
            'account' => [
                'account_name' => 'Sensitive Example',
                'account_number' => '0000123567890987',
                'bank_slug' => 'cbe',
                'bank_name' => 'Commercial Bank of Ethiopia',
            ],
            'auth_type' => 'PIN', 'otp_channel' => ['sms','email','telegram'],
            'otp_attempts' => 3, 'reason' => 'TEST_REASON',
            'updated_at' => '2026-11-14T10:00:00Z',
        ];
    }

    private function verified(string $status): VerifiedTransaction
    {
        return VerifiedTransaction::fromResponse(new ApiResponse(200, [
            'status' => 'success', 'data' => [
                'status' => $status, 'chapa_reference' => 'CHP123OTP',
                'merchant_reference' => 'PAYOUT123OTP',
                'currency' => 'ETB', 'amount' => 200000,
            ],
        ]), 'payout');
    }

    public function testDocumentedPayoutMetadataAndMaskedDestination(): void
    {
        $event = WebhookEvent::fromArray($this->payload());
        $meta = $event->payoutDetails();
        self::assertNotNull($meta);
        self::assertSame(200000, $event->amount);
        self::assertSame('customer_payout', $meta->payoutType);
        self::assertSame(6000, $meta->serviceFeeCents);
        self::assertSame('BANKREF123', $meta->processorReference);
        self::assertSame(['sms','email','telegram'], $meta->otpChannels);
        self::assertSame(3, $meta->otpAttempts);
        self::assertSame('TEST_REASON', $meta->reason);
        self::assertSame('PIN', $meta->authType);
        self::assertSame('cbe', $meta->bankSlug);
        self::assertSame('0987', $meta->accountLastFour);
        self::assertFalse(property_exists($meta, 'accountNumber'));
        self::assertStringNotContainsString('0000123567890987', json_encode($meta, JSON_THROW_ON_ERROR));
    }

    public function testEveryDocumentedPayoutEventIncludesTypedDetails(): void
    {
        foreach ([
            ['payout.success','success'], ['payout.failed','failed'],
            ['payout.reversed','reversed'], ['payout.blocked','blocked'],
            ['payout.auth_needed','auth_needed'], ['payout.otp_needed','otp_needed'],
            ['payout.otp_failed','otp_failed'],
        ] as [$name,$status]) {
            $event = WebhookEvent::fromArray($this->payload($name, $status));
            self::assertSame('customer_payout', $event->payoutDetails()?->payoutType);
            self::assertSame($name === 'payout.success', $event->signalsSuccessfulPayout());
        }
    }

    public function testInvalidMetadataIsRejected(): void
    {
        foreach ([
            ['otp_channel' => 'sms'], ['otp_channel' => ['sms', 5]],
            ['otp_channel' => ['SMS'] ], ['otp_attempts' => -1],
            ['otp_attempts' => '3'], ['service_fee' => '6.5'],
            ['service_fee' => -100], ['payout_type' => 'unknown'],
            ['processor_reference' => ['BANKREF']], ['account' => ['bank_slug' => ['cbe']]],
            ['account' => ['account_number' => 123456]],
        ] as $patch) {
            try {
                WebhookEvent::fromArray(array_replace($this->payload(), $patch));
                self::fail('Malformed metadata was accepted.');
            } catch (\UnexpectedValueException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testOtpNeededRequiresMatchingVerificationState(): void
    {
        $event = WebhookEvent::fromArray($this->payload());
        WebhookConsistency::assertCurrent($event, $this->verified('OTP_NEEDED'));
        $this->expectException(\UnexpectedValueException::class);
        WebhookConsistency::assertCurrent($event, $this->verified('SUCCESS'));
    }

    public function testOtpFailedDoesNotBecomeDeliveryOrOverrideSuccessfulPayout(): void
    {
        $event = WebhookEvent::fromArray($this->payload('payout.otp_failed','otp_failed'));
        WebhookConsistency::assertCurrent($event, $this->verified('OTP_NEEDED'));
        $notification = new ChapaWebhookProcessed(5, $event, $this->verified('SUCCESS'));
        self::assertFalse($notification->payoutMatches(200000, 'ETB', 'PAYOUT123OTP'));
        $old = WebhookEvent::fromArray($this->payload('payout.success','success'));
        $later = array_replace($this->payload('payout.otp_failed','otp_failed'), ['updated_at' => '2026-11-14T11:00:00Z']);
        self::assertSame(WebhookStateGuard::REVIEW, WebhookStateGuard::decide(WebhookEvent::fromArray($later), $old));
        $this->expectException(\UnexpectedValueException::class);
        WebhookConsistency::assertCurrent($event, $this->verified('SUCCESS'));
    }

    public function testReversalAfterSuccessStillReconciles(): void
    {
        $previous = WebhookEvent::fromArray($this->payload('payout.success', 'success'));
        $later = WebhookEvent::fromArray(array_replace(
            $this->payload('payout.reversed', 'reversed'),
            ['updated_at' => '2026-11-14T11:00:00Z'],
        ));
        self::assertSame(WebhookStateGuard::APPLY, WebhookStateGuard::decide($later, $previous));
        WebhookConsistency::assertCurrent($later, $this->verified('REVERSED'));
        $notification = new ChapaWebhookProcessed(6, $later, $this->verified('REVERSED'));
        self::assertFalse($notification->payoutMatches(200000, 'ETB', 'PAYOUT123OTP'));
    }
}
