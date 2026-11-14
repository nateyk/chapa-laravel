<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Http\RawResponse;
use Nateyk\Chapa\Laravel\ChapaServiceProvider;
use Nateyk\Chapa\Laravel\Http\Controllers\ChapaTransferApprovalController;
use Nateyk\Chapa\Laravel\Transfers\TransferApprovalGate;
use Nateyk\Chapa\Laravel\Transfers\TransferApprovalManager;
use Orchestra\Testbench\TestCase;

final class LaravelTransferApprovalTest extends TestCase
{
    private const SECRET = 'test-only-transfer-approval-secret-of-good-length';

    protected function getPackageProviders($app): array
    {
        return [ChapaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('z', 32)));
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('chapa.transfer_approval.enabled', true);
        $app['config']->set('chapa.secret_key', 'CHAPA_TEST_inert');
        $app['config']->set('chapa.transfer_approval.live_enabled', false);
        $app['config']->set('chapa.transfer_approval.secret', self::SECRET);
        $app['config']->set('chapa.transfer_approval.mode', 'test');
        $app['config']->set('chapa.transfer_approval.integration', 'merchant-one');
    }

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../database/migrations/2026_11_14_000002_create_chapa_transfer_approvals_table.php')->up();
        Route::post('/chapa/transfer-approval', ChapaTransferApprovalController::class);
    }

    private function payload(): array
    {
        return [
            'amount' => 10000,
            'currency' => 'ETB',
            'merchant_reference' => 'APPROVAL123',
            'account' => [
                'account_number' => '00123456789', 'account_name' => 'Abebe Kebede',
                'bank_slug' => 'telebirr',
            ],
            'reason' => 'Vendor payout',
        ];
    }

    private function callbackBody(array $changes = []): string
    {
        return json_encode(array_replace([
            'amount' => '100.00',
            'reference' => 'APPROVAL123',
            'bank' => 'telebirr',
            'account_name' => 'Abebe Kebede',
            'account_number' => '00123456789',
        ], $changes), JSON_THROW_ON_ERROR);
    }

    public function testRequiresAdminPreapprovalAndExactSignedDestination(): void
    {
        $transport = new MockTransport();
        $transport->responses[] = new RawResponse(200, '{"status":"success","data":{"chapa_reference":"CP_T_456","status":"PENDING"}}');
        $this->app->instance(ChapaClient::class, new ChapaClient('CHAPA_TEST_private', transport: $transport));
        $manager = $this->app->make(TransferApprovalManager::class);
        $gate = $this->app->make(TransferApprovalGate::class);
        $id = $manager->request($this->payload(), 'requester:22');
        self::assertGreaterThan(0, $id);
        $stored = DB::table('chapa_transfer_approvals')->where('id', $id)->first();
        self::assertSame('requested', $stored->state);
        self::assertStringNotContainsString('00123456789', $stored->payload_encrypted);
        $body = $this->callbackBody();
        $signature = hash_hmac('sha256', $body, self::SECRET);
        self::assertFalse($gate->check($body, $signature));
        self::assertFalse($manager->approve('APPROVAL123', 'requester:22')); // No self approval.
        self::assertTrue($manager->approve('APPROVAL123', 'admin:7'));
        self::assertFalse($manager->approve('APPROVAL123', 'admin:7'));
        self::assertFalse($gate->check($body, $signature)); // Approval alone cannot authorize callback.
        $created = $manager->dispatchApproved('APPROVAL123');
        self::assertSame('CP_T_456', $created->chapaReference);
        self::assertFalse($created->delivered());
        self::assertSame('submitted', DB::table('chapa_transfer_approvals')->where('id', $id)->value('state'));
        self::assertTrue($gate->check($body, $signature));
        $this->call('POST', '/chapa/transfer-approval', [], [], [], [
            'HTTP_CHAPA_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(200);
        self::assertTrue($gate->check($body, $signature)); // Replay is side-effect free.
        $queue = $manager->reviewQueue();
        // Submitted payouts do not appear in the outstanding admin review queue.
        self::assertCount(0, $queue);
        self::assertFalse($gate->check($body.' ', $signature));
        foreach ([
            ['amount' => '100.01'],
            ['account_number' => '00123456788'],
            ['account_name' => 'Other Person'],
            ['bank' => 'cbe'],
            ['reference' => 'OTHER'],
            ['currency' => 'USD'],
        ] as $changes) {
            $tampered = $this->callbackBody($changes);
            self::assertFalse($gate->check($tampered, hash_hmac('sha256', $tampered, self::SECRET)));
        }
        self::assertCount(1, $transport->requests);
        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertSame($stored->idempotency_key, $transport->requests[0]['headers']['Idempotency-Key']);
        $this->expectException(\LogicException::class);
        $manager->dispatchApproved('APPROVAL123'); // Cannot POST a second time.
    }

    public function testRejectAndDisabledModeNeverAllowTransfers(): void
    {
        $manager = $this->app->make(TransferApprovalManager::class);
        $manager->request($this->payload(), 'user:2');
        self::assertTrue($manager->reject('APPROVAL123', 'admin:3'));
        self::assertFalse($manager->approve('APPROVAL123', 'admin:4'));
        self::assertFalse($this->app->make(TransferApprovalGate::class)->check(
            $body = $this->callbackBody(), hash_hmac('sha256', $body, self::SECRET),
        ));
        $this->app['config']->set('chapa.transfer_approval.enabled', false);
        self::assertFalse($this->app->make(TransferApprovalGate::class)->check($body, hash_hmac('sha256', $body, self::SECRET)));
        $this->expectException(\LogicException::class);
        $manager->request($this->payload(), 'user:2');
    }

    public function testUnknownNetworkOutcomeRetainsSameIdempotencyKeyForManualReconciliation(): void
    {
        $transport = new MockTransport();
        $transport->responses[] = new RawResponse(500, '{"status":"error","message":"backend unavailable"}');
        $this->app->instance(ChapaClient::class, new ChapaClient('CHAPA_TEST_private', transport: $transport));
        $manager = $this->app->make(TransferApprovalManager::class);
        $id = $manager->request($this->payload(), 'user:2');
        self::assertTrue($manager->approve('APPROVAL123', 'admin:3'));
        $before = DB::table('chapa_transfer_approvals')->where('id', $id)->value('idempotency_key');
        try {
            $manager->dispatchApproved('APPROVAL123');
            self::fail('Expected API error.');
        } catch (\Nateyk\Chapa\Exceptions\ApiException) {
            self::assertTrue(true);
        }
        self::assertSame('unknown', DB::table('chapa_transfer_approvals')->where('id', $id)->value('state'));
        self::assertSame($before, DB::table('chapa_transfer_approvals')->where('id', $id)->value('idempotency_key'));
        self::assertCount(1, $transport->requests);
        self::assertTrue($this->app->make(TransferApprovalGate::class)->check(
            $body = $this->callbackBody(), hash_hmac('sha256', $body, self::SECRET),
        )); // Chapa might have sent the transfer despite the network error.
    }

    public function testConfiguredControllerReturnsOnly200Or400(): void
    {
        $body = $this->callbackBody();
        $this->call('POST', '/chapa/transfer-approval', [], [], [], [
            'HTTP_CHAPA_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);
        $this->call('POST', '/chapa/transfer-approval', [], [], [], [
            'HTTP_CHAPA_SIGNATURE' => 'bad-signature',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);
    }

    public function testReviewQueueContainsMaskedAccountsAndNeverPlainAccountNumbers(): void
    {
        $manager = $this->app->make(TransferApprovalManager::class);
        $manager->request($this->payload(), 'user:22');
        $queue = $manager->reviewQueue();
        self::assertCount(1, $queue);
        self::assertSame('Abebe Kebede', $queue[0]['account_name']);
        self::assertSame('telebirr', $queue[0]['bank_slug']);
        self::assertSame('*******6789', $queue[0]['account_number_masked']);
        self::assertStringNotContainsString('00123456789', json_encode($queue, JSON_THROW_ON_ERROR));
        self::assertTrue($manager->approve('APPROVAL123', 'admin:7'));
        self::assertCount(1, $manager->reviewQueue());
    }

    public function testExpiredRequestCannotBeApprovedOrDispatched(): void
    {
        $manager = $this->app->make(TransferApprovalManager::class);
        $id = $manager->request($this->payload(), 'user:22');
        DB::table('chapa_transfer_approvals')->where('id', $id)->update([
            'expires_at' => now()->subMinute(),
        ]);
        self::assertFalse($manager->approve('APPROVAL123', 'admin:7'));
        $this->expectException(\LogicException::class);
        $manager->dispatchApproved('APPROVAL123');
    }

    public function testMalformedDestinationAndMissingMerchantReferenceRejectedBeforeStorage(): void
    {
        $manager = $this->app->make(TransferApprovalManager::class);
        foreach ([
            array_replace($this->payload(), ['merchant_reference' => null]),
            array_replace($this->payload(), ['account' => ['bank_slug' => 'telebirr']]),
            array_replace($this->payload(), ['amount' => 99]),
        ] as $payload) {
            try {
                $manager->request($payload, 'user:2');
                self::fail('Expected failure.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(0, DB::table('chapa_transfer_approvals')->count());
    }
    public function testSubmittedPayoutCanBeVerifiedWithoutChangingItsApprovalState(): void
    {
        $transport = new MockTransport();
        $transport->responses[] = new RawResponse(200, '{"status":"success","data":{"chapa_reference":"CP_T_456","status":"PENDING"}}');
        $transport->responses[] = new RawResponse(200, '{"status":"success","data":{"chapa_reference":"CP_T_456","merchant_reference":"APPROVAL123","amount":10000,"currency":"ETB","status":"SUCCESS"}}');
        $this->app->instance(ChapaClient::class, new ChapaClient('CHAPA_TEST_private', transport: $transport));
        $manager = $this->app->make(TransferApprovalManager::class);
        $id = $manager->request($this->payload(), 'requester:22');
        self::assertTrue($manager->approve('APPROVAL123', 'admin:7'));
        $manager->dispatchApproved('APPROVAL123');
        $verified = $manager->verifySubmitted('APPROVAL123');
        self::assertTrue($verified->matchesPayout(10000, 'ETB', 'APPROVAL123'));
        self::assertSame('submitted', DB::table('chapa_transfer_approvals')->where('id', $id)->value('state'));
        self::assertSame(['POST', 'GET'], array_column($transport->requests, 'method'));
        self::assertStringContainsString('/v2/payouts/CP_T_456/verify', $transport->requests[1]['url']);
    }

    public function testUnknownOutcomeCanBeVerifiedButNeverAutomaticallyRetried(): void
    {
        $transport = new MockTransport();
        $transport->responses[] = new RawResponse(500, '{"status":"error","message":"unknown"}');
        $transport->responses[] = new RawResponse(200, '{"status":"success","data":{"chapa_reference":"CP_T_999","merchant_reference":"APPROVAL123","amount":10000,"currency":"ETB","status":"PENDING"}}');
        $this->app->instance(ChapaClient::class, new ChapaClient('CHAPA_TEST_private', transport: $transport));
        $manager = $this->app->make(TransferApprovalManager::class);
        $id = $manager->request($this->payload(), 'requester:22');
        self::assertTrue($manager->approve('APPROVAL123', 'admin:7'));
        try {
            $manager->dispatchApproved('APPROVAL123');
            self::fail('Expected uncertain payout creation.');
        } catch (\Nateyk\Chapa\Exceptions\ApiException) {
            self::assertTrue(true);
        }
        $verified = $manager->verifySubmitted('APPROVAL123');
        self::assertSame('PENDING', $verified->status);
        self::assertSame('unknown', DB::table('chapa_transfer_approvals')->where('id', $id)->value('state'));
        self::assertSame(['POST', 'GET'], array_column($transport->requests, 'method'));
    }

    public function testPayoutReconciliationRejectsMismatchedVerification(): void
    {
        $transport = new MockTransport();
        $transport->responses[] = new RawResponse(200, '{"status":"success","data":{"chapa_reference":"CP_T_456","status":"PENDING"}}');
        $transport->responses[] = new RawResponse(200, '{"status":"success","data":{"chapa_reference":"CP_T_456","merchant_reference":"APPROVAL123","amount":12000,"currency":"ETB","status":"SUCCESS"}}');
        $this->app->instance(ChapaClient::class, new ChapaClient('CHAPA_TEST_private', transport: $transport));
        $manager = $this->app->make(TransferApprovalManager::class);
        $manager->request($this->payload(), 'requester:22');
        $manager->approve('APPROVAL123', 'admin:7');
        $manager->dispatchApproved('APPROVAL123');
        $this->expectException(\UnexpectedValueException::class);
        $manager->verifySubmitted('APPROVAL123');
    }

    public function testRequestedPayoutCannotBeReconciledAndNoNetworkIsUsed(): void
    {
        $transport = new MockTransport();
        $this->app->instance(ChapaClient::class, new ChapaClient('CHAPA_TEST_private', transport: $transport));
        $manager = $this->app->make(TransferApprovalManager::class);
        $manager->request($this->payload(), 'requester:22');
        try {
            $manager->verifySubmitted('APPROVAL123');
            self::fail('Expected not-yet-dispatched rejection.');
        } catch (\LogicException) {
            self::assertCount(0, $transport->requests);
        }
    }

    public function testLiveTransfersCannotBeEnabledByTheGeneralSwitchAlone(): void
    {
        $this->app['config']->set('chapa.transfer_approval.mode', 'live');
        $this->app['config']->set('chapa.secret_key', 'CHAPA_LIVE_synthetic');
        $manager = $this->app->make(TransferApprovalManager::class);
        try {
            $manager->request($this->payload(), 'user:22');
            self::fail('Live transfer approval must require separate opt-in.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('separate explicit opt-in', $exception->getMessage());
        }
        self::assertFalse($this->app->make(TransferApprovalGate::class)->check(
            $body = $this->callbackBody(), hash_hmac('sha256', $body, self::SECRET),
        ));
        self::assertSame(0, DB::table('chapa_transfer_approvals')->count());
    }

    public function testMismatchedTestModeAndLiveKeyAreRejectedBeforeStorageOrNetwork(): void
    {
        $this->app['config']->set('chapa.secret_key', 'CHAPA_LIVE_incorrect_for_test');
        $manager = $this->app->make(TransferApprovalManager::class);
        $this->expectException(\LogicException::class);
        $manager->request($this->payload(), 'user:22');
    }

    public function testTestKeyIsNotAcceptedInLiveModeEvenAfterExplicitLiveOptIn(): void
    {
        $this->app['config']->set('chapa.transfer_approval.mode', 'live');
        $this->app['config']->set('chapa.transfer_approval.live_enabled', true);
        $this->app['config']->set('chapa.secret_key', 'CHAPA_TEST_not_live');
        $manager = $this->app->make(TransferApprovalManager::class);
        $this->expectException(\LogicException::class);
        $manager->request($this->payload(), 'user:22');
    }

}
