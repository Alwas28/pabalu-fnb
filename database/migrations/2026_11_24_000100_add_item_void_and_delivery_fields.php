<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - order_items.voided_quantity / void_reason: porsi yang dihapus dari open bill (dengan izin supervisor).
 * - orders.delivery_channel / delivery_reference: kanal delivery (antar sendiri, GoFood, …) & nomor pesanan aplikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('voided_quantity')->default(0)->after('quantity');
            $table->string('void_reason', 255)->nullable()->after('notes');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_channel', 20)->nullable()->after('delivery_address');
            $table->string('delivery_reference', 64)->nullable()->after('delivery_channel');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_channel', 'delivery_reference']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['voided_quantity', 'void_reason']);
        });
    }
};
