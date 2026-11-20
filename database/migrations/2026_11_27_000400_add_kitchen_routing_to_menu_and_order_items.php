<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu yang tidak perlu diproses dapur (mis. air mineral botol):
 * - menu_items.needs_kitchen    : pengaturan per menu (bawaan: ya).
 * - order_items.sent_to_kitchen : salinan saat pesanan dibuat, agar layar & tiket dapur tetap benar
 *                                 walau pengaturan menu diubah belakangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->boolean('needs_kitchen')->default(true)->after('is_active');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('sent_to_kitchen')->default(true)->after('kitchen_batch');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('sent_to_kitchen');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('needs_kitchen');
        });
    }
};
