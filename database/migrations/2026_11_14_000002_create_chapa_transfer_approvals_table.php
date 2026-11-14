<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapa_transfer_approvals', static function (Blueprint $table): void {
            $table->id();
            $table->string('integration', 64);
            $table->string('mode', 8);
            $table->string('merchant_reference', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3);
            $table->longText('payload_encrypted');
            $table->char('idempotency_key', 32);
            $table->string('state', 24)->default('requested');
            $table->string('requester', 120);
            $table->string('approver', 120)->nullable();
            $table->string('chapa_reference', 128)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatch_started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['integration', 'mode', 'merchant_reference'], 'chapa_approval_reference_unique');
            $table->index(['state', 'expires_at'], 'chapa_approval_queue_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapa_transfer_approvals');
    }
};
