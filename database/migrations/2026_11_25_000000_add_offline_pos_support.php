<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kasir offline (aplikasi Android):
 * - pos_devices: perangkat kasir terdaftar per outlet dengan kode pendek (K1, K2, …) untuk penomoran offline.
 * - outlets.offline_enabled / offline_max_hours: owner mengizinkan mode offline & batas lama offline.
 * - cash_shifts / cash_movements / orders.client_reference + pos_device_id: data dari perangkat (idempoten).
 * - orders.placed_offline / synced_at / sync_issues: penanda transaksi offline & catatan selisih saat sinkron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('code', 8);
            $table->string('name', 100);
            $table->string('device_uid', 100)->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'code']);
            $table->unique(['outlet_id', 'device_uid']);
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->boolean('offline_enabled')->default(false)->after('recipe_stock_deduction');
            $table->unsignedSmallInteger('offline_max_hours')->default(24)->after('offline_enabled');
        });

        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->string('client_reference', 64)->nullable()->after('user_id');
            $table->foreignId('pos_device_id')->nullable()->after('client_reference')->constrained()->nullOnDelete();
            $table->unique(['business_id', 'client_reference']);
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->string('client_reference', 64)->nullable()->after('user_id');
            $table->unique(['cash_shift_id', 'client_reference']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('pos_device_id')->nullable()->after('client_reference')->constrained()->nullOnDelete();
            $table->boolean('placed_offline')->default(false)->after('pos_device_id');
            $table->timestamp('synced_at')->nullable()->after('placed_offline');
            $table->json('sync_issues')->nullable()->after('synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_device_id');
            $table->dropColumn(['placed_offline', 'synced_at', 'sync_issues']);
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropUnique(['cash_shift_id', 'client_reference']);
            $table->dropColumn('client_reference');
        });

        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'client_reference']);
            $table->dropConstrainedForeignId('pos_device_id');
            $table->dropColumn('client_reference');
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['offline_enabled', 'offline_max_hours']);
        });

        Schema::dropIfExists('pos_devices');
    }
};
