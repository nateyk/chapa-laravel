<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel;

use Illuminate\Support\ServiceProvider;
use Nateyk\Chapa\ChapaClient;

final class ChapaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/chapa.php', 'chapa');

        $this->app->singleton(ChapaClient::class, static function ($app): ChapaClient {
            $config = $app['config']->get('chapa', []);

            return new ChapaClient(
                privateKey: (string) ($config['secret_key'] ?? ''),
                publicKey: $config['public_key'] ?? null,
                webhookSecret: $config['webhook_secret'] ?? null,
                baseUrl: (string) ($config['base_url'] ?? 'https://api.chapa.global'),
                timeout: (int) ($config['timeout'] ?? 15),
            );
        });

        $this->app->alias(ChapaClient::class, 'chapa');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/chapa.php' => config_path('chapa.php'),
        ], 'chapa-config');
    }
}
