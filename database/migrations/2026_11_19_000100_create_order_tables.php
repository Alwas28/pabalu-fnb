<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 2 — Order & pembayaran (Blueprint Khusus F&B bagian 7 & 10).
 *
 * - Nama, harga, pajak & service charge disalin ke order (snapshot) agar transaksi lama
 *   tidak berubah ketika menu atau pengaturan outlet diubah.
 * - Status order (pemenuhan) terpisah dari status pembayaran.
 * - Satu order dapat dibayar dengan beberapa metode (split payment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('number', 40);                       // KDI-261118-0001
            $table->string('receipt_token', 40)->unique();      // untuk struk web (QR) tanpa login
            $table->string('client_reference', 64)->nullable(); // idempotency key dari perangkat kasir

            $table->string('service_type', 16);
            $table->string('table_number', 20)->nullable();
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->text('delivery_address')->nullable();
            $table->string('notes', 500)->nullable();

            $table->string('status', 16)->index();
            $table->string('payment_status', 24)->index();

            $table->unsignedBigInteger('subtotal');
            $table->string('discount_type', 10)->nullable();    // amount / percent
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->string('discount_reason', 255)->nullable();
            $table->decimal('service_charge_rate', 5, 2)->default(0);
            $table->unsignedBigInteger('service_charge_amount')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('prices_include_tax')->default(false);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedBigInteger('change_amount')->default(0);

            $table->timestamp('placed_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'number']);
            $table->unique(['business_id', 'client_reference']);
            $table->index(['outlet_id', 'placed_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('menu_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('variant_name', 60)->nullable();
            $table->unsignedBigInteger('unit_price');    // harga menu + varian + modifier, per porsi
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('line_total');
            $table->string('notes', 255)->nullable();
            $table->string('kitchen_status', 16)->default('queued');
            $table->timestamps();
        });

        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_name', 80);
            $table->string('name', 80);
            $table->unsignedBigInteger('price');
        });

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('method', 24);
            $table->unsignedBigInteger('amount');                // porsi yang dialokasikan ke order
            $table->unsignedBigInteger('tendered_amount');       // uang yang diterima (tunai bisa lebih)
            $table->unsignedBigInteger('change_amount')->default(0);
            $table->string('reference', 100)->nullable();        // no. referensi QRIS / EDC / transfer
            $table->string('status', 16);
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'method']);
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_payments');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
