<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapa_webhook_receipts', static function (Blueprint $table): void {
            $table->id();
            $table->string('integration', 64);
            $table->string('mode', 8);
            $table->char('fingerprint', 64);
            $table->string('webhook_type', 32);
            $table->string('event_name', 128);
            $table->string('merchant_reference', 128)->nullable();
            $table->string('chapa_reference', 128)->nullable();
            $table->string('event_updated_at', 64)->nullable();
            $table->longText('payload_encrypted');
            $table->string('state', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->char('claim_token', 32)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error', 180)->nullable();
            $table->timestamps();
            $table->unique(['integration', 'mode', 'fingerprint'], 'chapa_inbox_delivery_unique');
            $table->index(['state', 'available_at'], 'chapa_inbox_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapa_webhook_receipts');
    }
};
