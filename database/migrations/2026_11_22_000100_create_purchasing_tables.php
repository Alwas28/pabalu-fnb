<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembelian & supplier (Blueprint Khusus F&B bagian 4 & 6):
 * PO → penerimaan barang (bertahap) → stok bertambah → tagihan & pembayaran → retur.
 * Tagihan dihitung dari barang yang diterima dikurangi retur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('contact_name', 120)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->string('status', 12)->index();          // draft / ordered / partial / received / cancelled
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('total')->default(0);           // nilai PO
            $table->unsignedBigInteger('received_total')->default(0);  // nilai barang diterima
            $table->unsignedBigInteger('returned_total')->default(0);  // nilai retur
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['outlet_id', 'number']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);                         // dalam satuan beli
            $table->string('unit_label', 20);                           // mis. "kg" atau "g"
            $table->decimal('unit_size', 14, 3);                        // isi satuan beli dalam satuan dasar
            $table->unsignedBigInteger('unit_price');                   // per satuan beli
            $table->unsignedBigInteger('line_total');
            $table->decimal('received_base_quantity', 14, 3)->default(0);
            $table->decimal('returned_base_quantity', 14, 3)->default(0);
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->timestamp('received_at');
            $table->string('notes', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('base_quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4);                         // per satuan dasar
        });

        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('method', 24);
            $table->string('reference', 100)->nullable();
            $table->date('paid_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 255);
            $table->unsignedBigInteger('total');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('base_quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4);
            $table->unsignedBigInteger('amount');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
