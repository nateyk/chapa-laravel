<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Illuminate\Support\Facades\Artisan;
use Nateyk\Chapa\Laravel\ChapaServiceProvider;
use Orchestra\Testbench\TestCase;

final class LaravelDoctorTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ChapaServiceProvider::class];
    }

    public function testDoctorOutputsOnlyFixedMetadataAndNeverClaimsLiveApproval(): void
    {
        $this->app['config']->set('chapa.secret_key', 'CHAPA_TEST_synthetic-secret-never-print');
        $this->app['config']->set('chapa.public_key', 'CHAPA_TEST_synthetic-public-never-print');
        $this->app['config']->set('chapa.webhook_secret', 'synthetic-webhook-signature-secret-never-print');
        self::assertSame(0, Artisan::call('chapa:doctor', ['--json' => true]));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('chapa_laravel_local_diagnostics', $report['kind']);
        self::assertTrue($report['checks']['private_key_configured']);
        self::assertTrue($report['checks']['public_key_configured_optional']);
        self::assertFalse($report['external_chapa_sandbox_verified']);
        self::assertFalse($report['live_money_certified']);
        self::assertStringNotContainsString('synthetic-secret-never-print', $output);
        self::assertStringNotContainsString('synthetic-public-never-print', $output);
        self::assertStringNotContainsString('synthetic-webhook-signature-secret-never-print', $output);
    }

    public function testMismatchedKnownKeyPrefixDoesNotClaimConfiguredReadiness(): void
    {
        $this->app['config']->set('chapa.secret_key', 'CHAPA_LIVE_synthetic');
        $this->app['config']->set('chapa.webhook_inbox.mode', 'test');
        self::assertSame(0, Artisan::call('chapa:doctor', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($report['checks']['known_key_prefix_matches_webhook_mode']);
        self::assertFalse($report['sdk_configuration_usable']);
    }
}
