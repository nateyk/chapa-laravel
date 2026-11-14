<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Nateyk\Chapa\Laravel\ChapaServiceProvider;
use Nateyk\Chapa\Laravel\Http\Controllers\ChapaTransferApprovalProbeController;
use Orchestra\Testbench\TestCase;

final class LaravelTransferApprovalProbeTest extends TestCase
{
    private const SECRET = 'test-only-approval-probe-secret-strong-enough';
    private const BODY = '{"amount":"100.00","reference":"TESTPAY1","bank":"telebirr","account_name":"Private Example","account_number":"00123456"}';

    protected function getPackageProviders($app): array
    {
        return [ChapaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('chapa.secret_key', 'CHAPA_TEST_inert');
        $app['config']->set('chapa.transfer_approval.mode', 'test');
        $app['config']->set('chapa.transfer_approval.enabled', false);
        $app['config']->set('chapa.transfer_approval.probe_enabled', true);
        $app['config']->set('chapa.transfer_approval.secret', self::SECRET);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/chapa/approval-probe', ChapaTransferApprovalProbeController::class);
    }

    public function testValidSignedProbeStillRejectsAndOnlyLogsRedactedEnums(): void
    {
        $signature = hash_hmac('sha256', self::BODY, self::SECRET);
        Log::shouldReceive('info')->once()->with(
            'Chapa test transfer approval signature probe',
            ['signature' => 'matched_raw_body_hmac', 'payload' => 'valid'],
        );
        $this->call('POST', '/chapa/approval-probe', [], [], [], [
            'HTTP_CHAPA_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], self::BODY)->assertStatus(400)->assertSeeText('');
    }

    public function testWrongSignatureReturns400WithoutExposingPayload(): void
    {
        Log::shouldReceive('info')->once()->with(
            'Chapa test transfer approval signature probe',
            ['signature' => 'mismatch', 'payload' => 'not_checked'],
        );
        $response = $this->call('POST', '/chapa/approval-probe', [], [], [], [
            'HTTP_CHAPA_SIGNATURE' => str_repeat('0', 64),
            'CONTENT_TYPE' => 'application/json',
        ], self::BODY)->assertStatus(400);
        self::assertSame('', $response->getContent());
    }

    public function testDisabledOrLiveModeOrLiveKeyReturns404WithoutLogging(): void
    {
        foreach ([
            ['chapa.transfer_approval.probe_enabled', false],
            ['chapa.transfer_approval.mode', 'live'],
            ['chapa.secret_key', 'CHAPA_LIVE_inert'],
        ] as [$key, $value]) {
            $this->app['config']->set('chapa.transfer_approval.probe_enabled', true);
            $this->app['config']->set('chapa.transfer_approval.mode', 'test');
            $this->app['config']->set('chapa.secret_key', 'CHAPA_TEST_inert');
            $this->app['config']->set($key, $value);
            $this->post('/chapa/approval-probe')->assertStatus(404);
        }
    }
}
