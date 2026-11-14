<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Dto\HostedCheckoutSession;
use Nateyk\Chapa\Http\ApiResponse;
use Nateyk\Chapa\Http\RawResponse;
use Nateyk\Chapa\Support\IdempotencyKey;
use Nateyk\Chapa\Support\MerchantReference;
use PHPUnit\Framework\TestCase;

final class HostedCheckoutSessionTest extends TestCase
{
    private function reply(string $url, mixed $created = '2026-10-05T09:30:00Z'): ApiResponse
    {
        return new ApiResponse(200, [
            'status' => 'success',
            'data' => [
                'checkout_url' => $url,
                'created_at' => $created,
                'expires_at' => '2026-10-06T09:30:00Z',
            ],
        ]);
    }

    public function testTypedCheckoutPreservesChapaUrlAndReference(): void
    {
        $url = 'https://checkout.chapa.global/CH0Vn2VASWCi';
        $result = HostedCheckoutSession::fromResponse($this->reply($url));
        self::assertSame($url, $result->checkoutUrl);
        self::assertSame('CH0Vn2VASWCi', $result->chapaReference);
        self::assertSame('2026-10-05T09:30:00Z', $result->createdAt);
        self::assertFalse($result->isExpired(new \DateTimeImmutable('2026-10-05T11:00:00Z')));
        self::assertTrue($result->isExpired(new \DateTimeImmutable('2026-10-07T11:00:00Z')));
    }

    public function testHostedTypedUsesSameIdempotentV2Endpoint(): void
    {
        $transport = new MockTransport();
        $transport->responses[] = new RawResponse(200,
            '{"status":"success","data":{"checkout_url":"https://checkout.chapa.global/CHSIM123","created_at":"2026-10-05T09:30:00Z"}}');
        $client = new ChapaClient('CHAPA_TEST_mock', transport: $transport);
        $session = $client->payments()->hostedTyped([
            'amount' => 10000, 'currency' => 'ETB',
            'merchant_reference' => MerchantReference::generate('ord'),
        ], 'persist-this-idempotency-key');
        self::assertSame('CHSIM123', $session->chapaReference);
        self::assertNull($session->isExpired());
        self::assertCount(1, $transport->requests);
        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertStringEndsWith('/v2/payments/hosted', $transport->requests[0]['url']);
        self::assertSame('persist-this-idempotency-key', $transport->requests[0]['headers']['Idempotency-Key']);
    }

    public function testOnlyTrustedHttpsCheckoutRedirectAccepted(): void
    {
        foreach ([
            'http://checkout.chapa.global/CH1',
            'https://checkout.chapa.global.evil.test/CH1',
            'https://checkout.chapa.global@evil.test/CH1',
            'https://user@checkout.chapa.global/CH1',
            'https://checkout.chapa.global:8443/CH1',
            'https://checkout.chapa.global/CH1#secret',
            'https://checkout.chapa.global/%2e%2e',
            'https://checkout.chapa.global/',
            'https://checkout.chapa.global/CH1%0d%0a',
            'https://evil.test/CH1',
            'https://checkout.chapa.global/CH1'."\r\n",
        ] as $url) {
            try {
                HostedCheckoutSession::fromResponse($this->reply($url));
                self::fail('Unsafe checkout redirect accepted.');
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    public function testExplicitTrustedHostOverrideDoesNotReconstructCheckoutUrl(): void
    {
        $custom = 'https://sandbox.chapa.example/CH123?ref=1';
        $session = HostedCheckoutSession::fromResponse($this->reply($custom), ['sandbox.chapa.example']);
        self::assertSame($custom, $session->checkoutUrl);
        self::assertSame('CH123', $session->chapaReference);
    }

    public function testInvalidApiAndTimestampsFailClosed(): void
    {
        $failed = new ApiResponse(200, ['status' => 'error', 'data' => [
            'checkout_url' => 'https://checkout.chapa.global/CH123',
        ]]);
        try {
            HostedCheckoutSession::fromResponse($failed);
            self::fail('Failed operation was accepted.');
        } catch (\UnexpectedValueException) {
            self::assertTrue(true);
        }

        $this->expectException(\UnexpectedValueException::class);
        HostedCheckoutSession::fromResponse($this->reply('https://checkout.chapa.global/CH123', 'not-a-date'));
    }

    public function testMerchantReferenceIsSecureBoundedAndDistinctFromIdempotencyKey(): void
    {
        $generated = [];
        for ($i = 0; $i < 128; ++$i) {
            $ref = MerchantReference::generate('ord');
            self::assertSame(20, strlen($ref));
            self::assertMatchesRegularExpression('/^ord-[A-Za-z0-9_-]{16}$/D', $ref);
            $generated[] = $ref;
        }
        self::assertCount(128, array_unique($generated));
        self::assertNotSame(MerchantReference::generate(), IdempotencyKey::generate());
    }

    public function testMerchantReferenceRejectsUnsafeOrLowEntropyPrefixes(): void
    {
        foreach (['', 'toolongprefix', '../etc', 'us er', 'éé', '-bad', "\n"] as $prefix) {
            try {
                MerchantReference::generate($prefix);
                self::fail('Invalid merchant reference prefix accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(20, strlen(MerchantReference::generate('abcdefg')));
    }
}
