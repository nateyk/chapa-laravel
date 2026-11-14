<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Illuminate\Support\ServiceProvider;
use Nateyk\Chapa\ChapaClient;
use Nateyk\Chapa\Laravel\ChapaServiceProvider;
use Nateyk\Chapa\Laravel\Facades\Chapa;
use Nateyk\Chapa\Resources\Balances;
use Nateyk\Chapa\Resources\Payments;
use Nateyk\Chapa\Resources\Payouts;
use Nateyk\Chapa\Support\MerchantReference;
use Orchestra\Testbench\TestCase;

final class LaravelPackageCompatibilityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ChapaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('chapa.secret_key', 'CHAPA_TEST_inert');
        $app['config']->set('chapa.public_key', 'CHAPA_TEST_inert_public');
    }

    public function testLaravelFacadeResolvesTheSameSingletonAsDependencyInjection(): void
    {
        $client = $this->app->make(ChapaClient::class);
        self::assertSame($client, $this->app->make('chapa'));
        self::assertInstanceOf(Payments::class, Chapa::payments());
        self::assertInstanceOf(Payouts::class, Chapa::payouts());
        self::assertInstanceOf(Balances::class, Chapa::balances());
        self::assertSame(20, strlen(MerchantReference::generate('ord')));
    }

    public function testPackagePublishesConfigurationAndOptionalMigrations(): void
    {
        $config = ServiceProvider::pathsToPublish(ChapaServiceProvider::class, 'chapa-config');
        $migrations = ServiceProvider::pathsToPublish(ChapaServiceProvider::class, 'chapa-migrations');

        self::assertNotEmpty($config);
        self::assertNotEmpty($migrations);
        self::assertContains(config_path('chapa.php'), array_values($config));
        self::assertContains(database_path('migrations'), array_values($migrations));
    }

    public function testClientConfigurationAndLiveApprovalDefaultsStaySafe(): void
    {
        $client = $this->app->make(ChapaClient::class);
        self::assertInstanceOf(ChapaClient::class, $client);
        self::assertSame('https://api.chapa.global', $this->app['config']->get('chapa.base_url'));
        self::assertFalse((bool) $this->app['config']->get('chapa.transfer_approval.enabled'));
        self::assertFalse((bool) $this->app['config']->get('chapa.transfer_approval.live_enabled'));
    }
}
