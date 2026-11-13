<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Exceptions\ApiException;
use Nateyk\Chapa\Http\RawResponse;
use Nateyk\Chapa\Support\PaymentVerifier;
use PHPUnit\Framework\TestCase;

final class ChapaClientTest extends TestCase
{
    private MockTransport $transport;
    private ChapaClient $client;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
        $this->client = new ChapaClient('private', 'public', 'webhook-secret', $this->transport);
    }

    public function testHostedRequestUsesPrivateKeyAndOperationKey(): void
    {
        $this->client->payments()->hosted(['amount' => 100, 'currency' => 'ETB'], 'order-1');
        self::assertSame('https://api.chapa.global/v2/payments/hosted', $this->transport->requests[0]['url']);
        self::assertSame('Bearer private', $this->transport->requests[0]['headers']['Authorization']);
        self::assertSame('order-1', $this->transport->requests[0]['headers']['Idempotency-Key']);
    }

    public function testInlineRequestUsesPublicKey(): void
    {
        $this->client->payments()->inline([
            'payment_method' => 'telebirr', 'mobile' => '251911000000', 'amount' => 100, 'currency' => 'ETB',
        ], 'inline-1');
        self::assertSame('Bearer public', $this->transport->requests[0]['headers']['Authorization']);
    }

    public function testApiErrorIsStructuredAndDoesNotRetry(): void
    {
        $this->transport->responses[] = new RawResponse(409, '{"status":"error","message":"Duplicate reference","error":{"code":"INVALID_STATE"}}');
        try {
            $this->client->refunds()->create(['payment_reference' => 'CH1'], 'refund-1');
            self::fail('Expected error');
        } catch (ApiException $exception) {
            self::assertSame(409, $exception->statusCode);
            self::assertSame('INVALID_STATE', $exception->errorCode);
        }
        self::assertCount(1, $this->transport->requests);
    }

    public function testRawWebhookSignatureAndServerVerification(): void
    {
        $body = '{"event":"payment.success"}';
        $signature = hash_hmac('sha256', $body, 'webhook-secret');
        self::assertTrue($this->client->webhooks()->verify($body, $signature));
        self::assertFalse($this->client->webhooks()->verify($body.' ', $signature));

        $this->transport->responses[] = new RawResponse(200, '{"status":"success","data":{"status":"SUCCESS","amount":10000,"currency":"ETB","merchant_reference":"ORDER1"}}');
        $verification = $this->client->payments()->verify('CH1');
        self::assertTrue(PaymentVerifier::matches($verification, 10000, 'ETB', 'ORDER1'));
        self::assertFalse(PaymentVerifier::matches($verification, 10000, 'ETB', 'OTHER'));
    }

    public function testRejectsFloatsAndPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client->payments()->hosted(['amount' => 1.50, 'currency' => 'ETB'], 'op-1');
    }

    public function testRejectsInvalidPaymentReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client->payments()->verify('../other');
    }
}
