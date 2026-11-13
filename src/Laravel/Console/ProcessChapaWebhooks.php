<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nateyk\Chapa\Laravel\Webhooks\WebhookProcessor;

/** Process due receipts. Schedule every minute; duplicate work is protected by atomic claims. */
final class ProcessChapaWebhooks extends Command
{
    protected $signature = 'chapa:webhooks:process {--limit=50 : Maximum receipts per run}';
    protected $description = 'Reconcile and dispatch pending Chapa v2 webhook receipts';

    public function handle(WebhookProcessor $processor): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 1000) {
            $this->error('Limit must be between 1 and 1000.');
            return self::FAILURE;
        }
        $now = now();
        $ids = DB::table('chapa_webhook_receipts')
            ->where('attempts', '<', max(1, (int) config('chapa.webhook_inbox.max_attempts', 8)))
            ->where(static function ($query) use ($now): void {
                $query->where(static function ($available) use ($now): void {
                    $available->whereIn('state', ['pending', 'failed'])->where('available_at', '<=', $now);
                })->orWhere(static function ($expired) use ($now): void {
                    $expired->where('state', 'processing')->where('lease_until', '<=', $now);
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $succeeded = 0;
        foreach ($ids as $id) {
            $succeeded += (int) $processor->process((int) $id);
        }
        $this->info(sprintf('Processed %d of %d due Chapa receipts.', $succeeded, $ids->count()));
        // An individual receipt failure is retained for backoff and later retry.
        return self::SUCCESS;
    }
}
