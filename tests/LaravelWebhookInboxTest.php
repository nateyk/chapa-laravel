<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Http\RawResponse;
use Nateyk\Chapa\Laravel\ChapaServiceProvider;
use Nateyk\Chapa\Laravel\Events\ChapaWebhookProcessed;
use Nateyk\Chapa\Laravel\Webhooks\WebhookInbox;
use Nateyk\Chapa\Laravel\Webhooks\WebhookProcessor;
use Orchestra\Testbench\TestCase;

final class LaravelWebhookInboxTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ChapaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('chapa.webhook_inbox.mode', 'test');
        $app['config']->set('chapa.webhook_inbox.integration', 'checkout-test');
    }

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../database/migrations/2026_11_13_000001_create_chapa_webhook_receipts_table.php')->up();
        $this->app->instance(ChapaClient::class, new ChapaClient('private', 'public', 'webhook-secret', new MockTransport()));
    }

    private function sampleBody(): string
    {
        return json_encode([
            'webhook_type' => 'payment', 'event' => 'payment.success', 'status' => 'success',
            'mode' => 'test', 'chapa_reference' => 'CH123', 'merchant_reference' => 'ORDER1',
            'amount' => '10000', 'currency' => 'ETB',
        ], JSON_THROW_ON_ERROR);
    }

    public function testAuthenticatedInboxDeduplicatesAndRejectsTampering(): void
    {
        $body = $this->sampleBody();
        $signature = hash_hmac('sha256', $body, 'webhook-secret');
        $inbox = $this->app->make(WebhookInbox::class);
        $first = $inbox->accept($body, $signature);
        $duplicate = $inbox->accept($body, $signature);
        self::assertTrue($first->newDelivery);
        self::assertFalse($duplicate->newDelivery);
        self::assertSame($first->receiptId, $duplicate->receiptId);
        self::assertSame(1, DB::table('chapa_webhook_receipts')->count());
        self::assertNotSame($body, DB::table('chapa_webhook_receipts')->value('payload_encrypted'));
        $this->expectException(\Nateyk\Chapa\Exceptions\ChapaException::class);
        $inbox->accept($body.' ', $signature);
    }

    public function testVerifiedPaymentDispatchesOnceThenMarksProcessed(): void
    {
        $mock = new MockTransport();
        $mock->responses[] = new RawResponse(200, '{"status":"success","data":{"status":"SUCCESS","chapa_reference":"CH123","merchant_reference":"ORDER1","amount":10000,"currency":"ETB"}}');
        $this->app->instance(ChapaClient::class, new ChapaClient('private', 'public', 'webhook-secret', $mock));
        Event::fake([ChapaWebhookProcessed::class]);
        $body = $this->sampleBody();
        $receipt = $this->app->make(WebhookInbox::class)->accept($body, hash_hmac('sha256', $body, 'webhook-secret'));
        $processor = $this->app->make(WebhookProcessor::class);
        self::assertTrue($processor->process($receipt->receiptId));
        self::assertFalse($processor->process($receipt->receiptId));
        self::assertSame('processed', DB::table('chapa_webhook_receipts')->where('id', $receipt->receiptId)->value('state'));
        Event::assertDispatched(ChapaWebhookProcessed::class, static fn ($event): bool => $event->paymentMatches(10000, 'ETB', 'ORDER1'));
        self::assertCount(1, $mock->requests);
    }

    public function testRefundWebhookDoesNotGuessRefundReference(): void
    {
        $body = '{"webhook_type":"refund","event":"payment.partially_refunded","status":"partially_refunded","mode":"test","chapa_reference":"CH123","amount":"10000","refunded_amount":"5000","currency":"ETB"}';
        Event::fake([ChapaWebhookProcessed::class]);
        $receipt = $this->app->make(WebhookInbox::class)->accept($body, hash_hmac('sha256', $body, 'webhook-secret'));
        self::assertTrue($this->app->make(WebhookProcessor::class)->process($receipt->receiptId));
        Event::assertDispatched(ChapaWebhookProcessed::class, static fn ($event): bool => $event->webhook->type === 'refund' && $event->verification === null);
    }
}
