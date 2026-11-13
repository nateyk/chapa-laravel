<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Nateyk\Chapa\Support\WebhookVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookEventTest extends TestCase
{
    public function testPaymentSignatureUsesExactBodyBytesAndConvertsCents(): void
    {
        $body = '{"webhook_type":"payment","event":"payment.success","status":"success","mode":"test","amount":"10000","currency":"ETB"}';
        $verifier = new WebhookVerifier('secret');
        $signature = hash_hmac('sha256', $body, 'secret');
        $event = $verifier->event($body, $signature);
        self::assertTrue($event->signalsSuccessfulPayment());
        self::assertSame(10000, $event->amount);
        self::assertFalse($verifier->verify($body.' ', $signature));
    }

    public function testRefundEventsUseRefundTypeWithPaymentEventName(): void
    {
        $body = '{"webhook_type":"refund","event":"payment.partially_refunded","status":"partially_refunded","amount":"40000","refunded_amount":"15000"}';
        $event = (new WebhookVerifier('secret'))->event($body, hash_hmac('sha256', $body, 'secret'));
        self::assertSame('refund', $event->type);
        self::assertSame(15000, $event->refundedAmount);
        self::assertFalse($event->signalsSuccessfulPayment());
    }

    public function testInvalidEventTypeAndNameCombinationRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $body = '{"webhook_type":"refund","event":"payment.success","status":"success"}';
        (new WebhookVerifier('secret'))->event($body, hash_hmac('sha256', $body, 'secret'));
    }

    public function testOverflowedMoneyFieldRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $body = '{"webhook_type":"payment","event":"payment.success","status":"success","amount":"999999999999999999999999999999"}';
        (new WebhookVerifier('secret'))->event($body, hash_hmac('sha256', $body, 'secret'));
    }
}
