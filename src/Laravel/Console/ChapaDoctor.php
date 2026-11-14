<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Local-only setup diagnostics. Never contacts Chapa or reveals key contents.
 * An informational success status does NOT certify payment or payout readiness.
 */
final class ChapaDoctor extends Command
{
    protected $signature = 'chapa:doctor {--json : Print a sanitized machine-readable report}';
    protected $description = 'Inspect Chapa Laravel configuration and optional migration readiness without API calls';

    public function handle(): int
    {
        $private = config('chapa.secret_key');
        $public = config('chapa.public_key');
        $webhook = config('chapa.webhook_secret');
        $mode = config('chapa.webhook_inbox.mode', 'test');
        $approval = config('chapa.transfer_approval', []);

        $knownMode = in_array($mode, ['test', 'live'], true);
        $keyMode = is_string($private) && str_starts_with($private, 'CHAPA_TEST_') ? 'test'
            : (is_string($private) && str_starts_with($private, 'CHAPA_LIVE_') ? 'live' : null);

        // The webhook and approval migrations are optional. Database connectivity
        // failures should never leak DSNs, DB passwords or raw exception messages.
        $webhookTable = false;
        $approvalTable = false;
        try {
            $webhookTable = Schema::hasTable('chapa_webhook_receipts');
            $approvalTable = Schema::hasTable('chapa_transfer_approvals');
        } catch (\Throwable) {
            // Expected before DB setup; report safe Boolean diagnostics only.
        }

        $checks = [
            'php_8_2_plus' => PHP_VERSION_ID >= 80200,
            'json_extension' => extension_loaded('json'),
            'private_key_configured' => is_string($private) && trim($private) !== '',
            'public_key_configured_optional' => is_string($public) && trim($public) !== '',
            'webhook_secret_configured_optional' => is_string($webhook) && trim($webhook) !== '',
            'webhook_inbox_migration_optional' => $webhookTable,
            'transfer_approval_migration_optional' => $approvalTable,
            'webhook_mode_valid' => $knownMode,
            'known_key_prefix_matches_webhook_mode' => $keyMode === null || ($knownMode && $keyMode === $mode),
            'transfer_approval_disabled_by_default' => ($approval['enabled'] ?? false) !== true,
            'live_transfer_approval_disabled_by_default' => ($approval['live_enabled'] ?? false) !== true,
        ];
        $report = [
            'kind' => 'chapa_laravel_local_diagnostics',
            'checks' => $checks,
            'sdk_configuration_usable' => $checks['php_8_2_plus']
                && $checks['json_extension']
                && $checks['private_key_configured']
                && $checks['webhook_mode_valid']
                && $checks['known_key_prefix_matches_webhook_mode'],
            'external_chapa_sandbox_verified' => false,
            'live_money_certified' => false,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info('Chapa Laravel SDK — local setup diagnostics (no network requests)');
            foreach ($checks as $name => $passed) {
                $this->line(($passed ? 'YES ' : 'NO  ').$name);
            }
            $this->warn('Optional checks may be NO until you configure webhooks and publish migrations.');
            $this->warn('No genuine Chapa test-mode transaction or Approval URL signature has been verified.');
        }

        return self::SUCCESS; // Informational command; consult report booleans for readiness.
    }
}
