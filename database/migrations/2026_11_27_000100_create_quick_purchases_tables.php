<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Belanja Cepat: belanja bahan tunai tanpa PO & supplier (mis. ke pasar / warung).
 * Stok bertambah dengan HPP = harga beli; uang keluar masuk arus kas. Tidak dihitung sebagai
 * pengeluaran operasional karena biaya bahan sudah masuk laba lewat HPP resep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->date('purchased_at')->index();
            $table->string('store_name', 80)->nullable();
            $table->string('method', 24);
            $table->unsignedBigInteger('total');
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('quick_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quick_purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->decimal('base_quantity', 14, 3);
            $table->unsignedBigInteger('total_price');
            $table->decimal('unit_cost', 14, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_purchase_items');
        Schema::dropIfExists('quick_purchases');
    }
};
