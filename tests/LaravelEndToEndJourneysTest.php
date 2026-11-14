<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Http\RawResponse;
use Nateyk\Chapa\Laravel\ChapaServiceProvider;
use Nateyk\Chapa\Laravel\Events\ChapaWebhookProcessed;
use Nateyk\Chapa\Laravel\Http\Controllers\ChapaTransferApprovalController;
use Nateyk\Chapa\Laravel\Http\Controllers\ChapaWebhookController;
use Nateyk\Chapa\Laravel\Transfers\TransferApprovalManager;
use Orchestra\Testbench\TestCase;

/**
 * Real Laravel HTTP router, auth middleware, Gate, durable SQLite inbox,
 * Artisan worker and mocked external Chapa network. No real Chapa calls.
 */
final class LaravelEndToEndJourneysTest extends TestCase
{
    private const WEBHOOK_SECRET = 'test-only-webhook-secret';
    private const APPROVAL_SECRET = 'test-only-approval-secret-with-enough-entropy';

    protected function getPackageProviders($app): array
    {
        return [ChapaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('p', 32)));
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('chapa.secret_key', 'CHAPA_TEST_inert_private');
        $app['config']->set('chapa.webhook_secret', self::WEBHOOK_SECRET);
        $app['config']->set('chapa.webhook_inbox.mode', 'test');
        $app['config']->set('chapa.webhook_inbox.integration', 'merchant-test-1');
        $app['config']->set('chapa.transfer_approval.enabled', true);
        $app['config']->set('chapa.transfer_approval.live_enabled', false);
        $app['config']->set('chapa.transfer_approval.secret', self::APPROVAL_SECRET);
        $app['config']->set('chapa.transfer_approval.mode', 'test');
        $app['config']->set('chapa.transfer_approval.integration', 'merchant-test-1');
    }

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../database/migrations/2026_11_13_000001_create_chapa_webhook_receipts_table.php')->up();
        (require __DIR__.'/../database/migrations/2026_11_14_000002_create_chapa_transfer_approvals_table.php')->up();
        Schema::create('synthetic_merchant_orders', static function (Blueprint $table): void {
            $table->id();
            $table->string('merchant_reference')->unique();
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3);
            $table->timestamp('fulfilled_at')->nullable();
        });

        Route::post('/chapa/webhook', ChapaWebhookController::class);
        Route::post('/chapa/transfer-approval', ChapaTransferApprovalController::class);
        Gate::define('manage-chapa-payouts', static fn ($user): bool => ($user->role ?? '') === 'admin');

        // Illustrative host-owned routes. The SDK never registers these.
        Route::middleware(['auth', 'can:manage-chapa-payouts'])->group(static function (): void {
            Route::post('/admin/chapa/{reference}/approve',
                static function (Request $request, string $reference, TransferApprovalManager $manager) {
                    abort_unless($manager->approve($reference, 'admin:'.$request->user()->getAuthIdentifier()), 409);
                    return response()->noContent();
                });
            Route::post('/admin/chapa/{reference}/dispatch',
                static function (string $reference, TransferApprovalManager $manager) {
                    try {
                        $receipt = $manager->dispatchApproved($reference);
                    } catch (\LogicException) {
                        abort(409);
                    }
                    return response()->json(['status' => $receipt->status->value], 202);
                });
        });
    }

    /** @return array<string,mixed> */
    private function payout(): array
    {
        return [
            'amount' => 10000, 'currency' => 'ETB', 'merchant_reference' => 'PAYREQ123',
            'account' => [
                'account_number' => '00123456789',
                'account_name' => 'Synthetic Recipient',
                'bank_slug' => 'telebirr',
            ],
        ];
    }

    private function approvalCallback(): string
    {
        return json_encode([
            'amount' => '100.00',
            'reference' => 'PAYREQ123',
            'bank' => 'telebirr',
            'account_name' => 'Synthetic Recipient',
            'account_number' => '00123456789',
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function paymentWebhook(string $updatedAt = '2026-11-14T09:00:00Z'): array
    {
        return [
            'webhook_type' => 'payment', 'event' => 'payment.success',
            'status' => 'success', 'mode' => 'test',
            'chapa_reference' => 'CHPAY123', 'merchant_reference' => 'ORDER123',
            'amount' => '10000', 'currency' => 'ETB', 'updated_at' => $updatedAt,
        ];
    }

    public function testSignedHttpWebhookIsStoredOnceThenVerifiedByArtisanBeforeSingleFulfillment(): void
    {
        $transport = new MockTransport();
        for ($i = 0; $i < 2; ++$i) {
            $transport->responses[] = new RawResponse(200, '{"status":"success","data":{"chapa_reference":"CHPAY123","merchant_reference":"ORDER123","amount":10000,"currency":"ETB","status":"SUCCESS"}}');
        }
        $this->app->instance(ChapaClient::class, new ChapaClient(
            'CHAPA_TEST_inert_private', webhookSecret: self::WEBHOOK_SECRET, transport: $transport,
        ));
        DB::table('synthetic_merchant_orders')->insert([
            'merchant_reference' => 'ORDER123', 'amount_cents' => 10000, 'currency' => 'ETB',
        ]);

        // The consuming application's fulfillment is an atomic, idempotent DB update.
        $fulfillments = 0;
        Event::listen(ChapaWebhookProcessed::class, static function (ChapaWebhookProcessed $event) use (&$fulfillments): void {
            if ($event->paymentMatches(10000, 'ETB', 'ORDER123')) {
                $fulfillments += DB::table('synthetic_merchant_orders')
                    ->where('merchant_reference', 'ORDER123')
                    ->whereNull('fulfilled_at')
                    ->update(['fulfilled_at' => now()]);
            }
        });

        $body = json_encode($this->paymentWebhook(), JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, self::WEBHOOK_SECRET);
        $this->call('POST', '/chapa/webhook', [], [], [], [
            'HTTP_X_CHAPA_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(200)->assertJsonPath('duplicate', false);
        $this->call('POST', '/chapa/webhook', [], [], [], [
            'HTTP_X_CHAPA_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(200)->assertJsonPath('duplicate', true);

        // Chapa may send another signed update with distinct raw bytes for the same payment.
        $later = json_encode($this->paymentWebhook('2026-11-14T09:01:00Z'), JSON_THROW_ON_ERROR);
        $this->call('POST', '/chapa/webhook', [], [], [], [
            'HTTP_X_CHAPA_SIGNATURE' => hash_hmac('sha256', $later, self::WEBHOOK_SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $later)->assertStatus(200);
        self::assertSame(2, DB::table('chapa_webhook_receipts')->count());
        self::assertSame(0, $fulfillments); // Ingress never fulfills.

        self::assertSame(0, Artisan::call('chapa:webhooks:process', ['--limit' => 10]));
        self::assertSame(1, $fulfillments);
        self::assertSame(2, DB::table('chapa_webhook_receipts')->where('state', 'processed')->count());
        self::assertCount(2, $transport->requests);
        self::assertSame(['GET', 'GET'], array_column($transport->requests, 'method')); // No money movement.
        self::assertNotNull(DB::table('synthetic_merchant_orders')->where('merchant_reference', 'ORDER123')->value('fulfilled_at'));
        self::assertSame(0, Artisan::call('chapa:webhooks:process', ['--limit' => 10]));
        self::assertSame(1, $fulfillments);
    }

    public function testWebhookHttpRejectsInvalidSignatureAndCrossEnvironmentEventBeforePersistence(): void
    {
        $body = json_encode($this->paymentWebhook(), JSON_THROW_ON_ERROR);
        $this->call('POST', '/chapa/webhook', [], [], [], [
            'HTTP_X_CHAPA_SIGNATURE' => str_repeat('a', 64), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);
        $this->call('POST', '/chapa/webhook', [], [], [], [
            'HTTP_X_CHAPA_SIGNATURE' => hash_hmac('sha256', $body, 'wrong-secret'),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);

        $live = json_encode(array_replace($this->paymentWebhook(), ['mode' => 'live']), JSON_THROW_ON_ERROR);
        $this->call('POST', '/chapa/webhook', [], [], [], [
            'HTTP_X_CHAPA_SIGNATURE' => hash_hmac('sha256', $live, self::WEBHOOK_SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $live)->assertStatus(422);
        self::assertSame(0, DB::table('chapa_webhook_receipts')->count());
    }

    public function testAdminHttpJourneyForbidsGuestAndMemberAndRequiresApprovalBeforePayout(): void
    {
        $transport = new MockTransport();
        $transport->responses[] = new RawResponse(200,
            '{"status":"success","data":{"chapa_reference":"CP_TEST_ABC","status":"PENDING"}}');
        $this->app->instance(ChapaClient::class, new ChapaClient(
            'CHAPA_TEST_inert_private', transport: $transport,
        ));
        $manager = $this->app->make(TransferApprovalManager::class);
        $manager->request($this->payout(), 'user:5');
        $url = '/admin/chapa/PAYREQ123';
        $this->withHeader('Accept', 'application/json')->post($url.'/approve')->assertStatus(401);
        $this->actingAs(new GenericUser(['id' => 8, 'role' => 'member']));
        $this->post($url.'/approve')->assertStatus(403);
        $this->post($url.'/dispatch')->assertStatus(403);

        $this->actingAs(new GenericUser(['id' => 7, 'role' => 'admin']));
        $this->post($url.'/dispatch')->assertStatus(409);
        self::assertCount(0, $transport->requests);
        $this->post($url.'/approve')->assertStatus(204);
        $this->post($url.'/approve')->assertStatus(409);
        $this->post($url.'/dispatch')->assertStatus(202)->assertJsonPath('status', 'PENDING');
        $this->post($url.'/dispatch')->assertStatus(409);
        self::assertSame('submitted', DB::table('chapa_transfer_approvals')->where('merchant_reference','PAYREQ123')->value('state'));
        self::assertCount(1, $transport->requests);
        self::assertSame('POST', $transport->requests[0]['method']);

        // Authenticated admin decision and Chapa's synchronous callback are separate.
        $body = $this->approvalCallback();
        $this->call('POST', '/chapa/transfer-approval', [], [], [], [
            'HTTP_CHAPA_SIGNATURE' => hash_hmac('sha256', $body, self::APPROVAL_SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(200);
        $this->call('POST', '/chapa/transfer-approval', [], [], [], [
            'HTTP_CHAPA_SIGNATURE' => 'invalid', 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);
        self::assertCount(1, $transport->requests); // Callback never resubmits payout.
    }
}
