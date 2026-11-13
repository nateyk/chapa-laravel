<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RetryChapaWebhook extends Command
{
    protected $signature = 'chapa:webhooks:retry {receiptId : Receipt ID to requeue}';
    protected $description = 'Requeue a failed Chapa webhook for verified processing';

    public function handle(): int
    {
        $id = filter_var($this->argument('receiptId'), FILTER_VALIDATE_INT);
        if (!is_int($id) || $id < 1) {
            $this->error('Receipt ID must be a positive integer.');
            return self::FAILURE;
        }
        $updated = DB::table('chapa_webhook_receipts')->where('id', $id)->where('state', 'failed')->update([
            'state' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'last_error' => null,
            'updated_at' => now(),
        ]);
        $this->info($updated === 1 ? 'Receipt requeued.' : 'No failed receipt found.');
        return $updated === 1 ? self::SUCCESS : self::FAILURE;
    }
}
