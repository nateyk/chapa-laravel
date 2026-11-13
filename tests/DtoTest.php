<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Dto\DirectChargeRequest;
use Nateyk\Chapa\Dto\HostedCheckoutRequest;
use Nateyk\Chapa\Dto\InlineChargeRequest;
use Nateyk\Chapa\Dto\PayoutRequest;
use Nateyk\Chapa\Dto\RefundRequest;
use Nateyk\Chapa\Dto\VerifiedTransaction;
use Nateyk\Chapa\Http\ApiResponse;
use PHPUnit\Framework\TestCase;

final class DtoTest extends TestCase
{
    public function testTypedBodiesRemainCompatibleWithExistingResources(): void
    {
        $transport = new MockTransport();
        $client = new ChapaClient('private', 'public', null, $transport);
        $client->payments()->hosted(new HostedCheckoutRequest(10000, 'ETB', 'ORDER1', ['email' => 'buyer@example.org']), 'k1');
        $client->payments()->direct(new DirectChargeRequest(10000, 'ETB', 'telebirr', '251911000000'), 'k2');
        $client->payments()->inline(new InlineChargeRequest(10000, 'ETB', 'telebirr', '251911000000'), 'k3');
        $client->payouts()->create(new PayoutRequest(10000, 'ETB', ['bank_slug' => 'telebirr']), 'k4');
        $client->refunds()->create(new RefundRequest('CH123', 1000), 'k5');

        self::assertCount(5, $transport->requests);
        $hosted = json_decode($transport->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        $direct = json_decode($transport->requests[1]['body'], true, flags: JSON_THROW_ON_ERROR);
        $inline = json_decode($transport->requests[2]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(10000, $hosted['amount']);
        self::assertSame('251911000000', $direct['customer']['phone_number']);
        self::assertSame('251911000000', $inline['mobile']);
        self::assertArrayNotHasKey('customer', $inline);
        self::assertArrayNotHasKey('meta', $direct);
        self::assertSame('Bearer public', $transport->requests[2]['headers']['Authorization']);
    }

    public function testVerifiedPaymentComparesAllRequiredFields(): void
    {
        $result = VerifiedTransaction::fromResponse(new ApiResponse(200, [
            'status' => 'success',
            'data' => [
                'chapa_reference' => 'CH123', 'merchant_reference' => 'ORDER1',
                'amount' => 10000, 'currency' => 'ETB', 'status' => 'SUCCESS',
            ],
        ]), 'payment');
        self::assertTrue($result->matchesPayment(10000, 'ETB', 'ORDER1'));
        self::assertFalse($result->matchesPayment(10001, 'ETB', 'ORDER1'));
        self::assertFalse($result->matchesPayment(10000, 'USD', 'ORDER1'));
        self::assertFalse($result->matchesPayment(10000, 'ETB', 'ORDER2'));
    }

    public function testMissingChapaReferenceIsRejectedFailClosed(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        VerifiedTransaction::fromResponse(new ApiResponse(200, [
            'status' => 'success',
            'data' => [
                'merchant_reference' => 'ORDER1',
                'amount' => 10000, 'currency' => 'ETB', 'status' => 'SUCCESS',
            ],
        ]), 'payment');
    }

    public function testRequestRejectsUnsecuredCallbackUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HostedCheckoutRequest(10000, 'ETB', callbackUrl: 'http://example.com/hook');
    }

    public function testWrongAmountTypeIsNotAcceptedInTypedVerification(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        VerifiedTransaction::fromResponse(new ApiResponse(200, [
            'status' => 'success',
            'data' => ['chapa_reference' => 'CH123', 'amount' => '10000', 'currency' => 'ETB', 'status' => 'SUCCESS'],
        ]), 'payment');
    }
}
