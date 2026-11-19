<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox event untuk Analytics Platform / Central Dashboard: event dicatat setelah transaksi bisnis
 * berhasil, lalu dikirim berkelompok oleh `pabalu:outbox-dispatch` dengan percobaan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 60)->index();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['dispatched_at', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
