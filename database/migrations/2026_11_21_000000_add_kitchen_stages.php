<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antrean dapur (Blueprint Khusus F&B bagian 5 & 7).
 *
 * - Tahap "Antre" & "Disajikan" selalu ada; "Dimasak" & "Siap" bisa dinyalakan/dimatikan per outlet.
 * - kitchen_batch: nomor kiriman ke dapur (pesanan awal = 1, tiap tambahan open bill +1), untuk tiket dapur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->boolean('kitchen_use_preparing')->default(true)->after('kitchen_mode');
            $table->boolean('kitchen_use_ready')->default(true)->after('kitchen_use_preparing');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('kitchen_batch')->default(1)->after('kitchen_status');
            $table->timestamp('kitchen_updated_at')->nullable()->after('kitchen_batch');
            $table->index(['order_id', 'kitchen_status']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'kitchen_status']);
            $table->dropColumn(['kitchen_batch', 'kitchen_updated_at']);
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['kitchen_use_preparing', 'kitchen_use_ready']);
        });
    }
};
