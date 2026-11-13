<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Nateyk\Chapa\Dto\VerifiedTransaction;
use Nateyk\Chapa\Http\ApiResponse;
use Nateyk\Chapa\Webhooks\WebhookConsistency;
use Nateyk\Chapa\Webhooks\WebhookEvent;
use Nateyk\Chapa\Webhooks\WebhookStateGuard;
use PHPUnit\Framework\TestCase;

final class WebhookPolicyTest extends TestCase
{
    private function event(string $type, string $name, string $status, string $time = '2026-11-13T10:00:00Z'): WebhookEvent
    {
        return WebhookEvent::fromArray([
            'webhook_type' => $type, 'event' => $name, 'status' => $status,
            'chapa_reference' => 'CH123', 'mode' => 'test', 'updated_at' => $time,
        ]);
    }

    public function testRejectsUndocumentedAndContradictoryEventStates(): void
    {
        foreach ([
            ['payment','payment.success','failed'],
            ['payment','payment.unknown','unknown'],
            ['payout','payout.otp_failed','failed'],
            ['refund','payment.fully_refunded','partially_refunded'],
        ] as [$type,$name,$status]) {
            try {
                $this->event($type,$name,$status);
                self::fail('Invalid event accepted: '.$name);
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAllDocumentedEventAndStatusPairsAreSupported(): void
    {
        foreach ([
            ['payment','payment.success','success'],
            ['payment','payment.failed','failed'],
            ['payment','payment.cancelled','cancelled'],
            ['payment','payment.incomplete','incomplete'],
            ['payment','payment.auth_needed','auth_needed'],
            ['payment','payment.blocked','blocked'],
            ['payout','payout.success','success'],
            ['payout','payout.failed','failed'],
            ['payout','payout.reversed','reversed'],
            ['payout','payout.blocked','blocked'],
            ['payout','payout.auth_needed','auth_needed'],
            ['payout','payout.otp_needed','otp_needed'],
            ['payout','payout.otp_failed','otp_failed'],
            ['refund','payment.partially_refunded','partially_refunded'],
            ['refund','payment.fully_refunded','fully_refunded'],
        ] as [$type,$name,$status]) {
            self::assertSame($status, $this->event($type,$name,$status)->status);
        }
    }

    public function testStaleFailureCannotOverwriteSuccess(): void
    {
        $old = $this->event('payment','payment.success','success');
        $stale = $this->event('payment','payment.failed','failed','2026-11-13T09:00:00Z');
        $later = $this->event('payment','payment.failed','failed','2026-11-13T11:00:00Z');
        self::assertSame(WebhookStateGuard::IGNORE_STALE, WebhookStateGuard::decide($stale, $old));
        self::assertSame(WebhookStateGuard::REVIEW, WebhookStateGuard::decide($later, $old));
    }

    public function testPayoutReversalAfterSuccessCanBeApplied(): void
    {
        $old = $this->event('payout','payout.success','success');
        $new = $this->event('payout','payout.reversed','reversed','2026-11-13T11:00:00Z');
        self::assertSame(WebhookStateGuard::APPLY, WebhookStateGuard::decide($new, $old));
    }

    public function testUnknownTimeDoesNotGuessOrdering(): void
    {
        $old = WebhookEvent::fromArray(['webhook_type'=>'payment','event'=>'payment.failed','status'=>'failed','chapa_reference'=>'CH123']);
        $new = WebhookEvent::fromArray(['webhook_type'=>'payment','event'=>'payment.success','status'=>'success','chapa_reference'=>'CH123']);
        self::assertSame(WebhookStateGuard::REVIEW, WebhookStateGuard::decide($new, $old));
    }

    public function testContradictoryVerificationIsRejected(): void
    {
        $verified = VerifiedTransaction::fromResponse(new ApiResponse(200, [
            'status' => 'success', 'data' => [
                'chapa_reference' => 'CH123', 'merchant_reference' => 'ORDER1',
                'amount' => 10000, 'currency' => 'ETB', 'status' => 'SUCCESS',
            ],
        ]), 'payment');
        $this->expectException(\UnexpectedValueException::class);
        WebhookConsistency::assertCurrent($this->event('payment','payment.failed','failed'), $verified);
    }
}
