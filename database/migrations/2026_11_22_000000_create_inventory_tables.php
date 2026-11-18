<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 5 — Persediaan & resep (Blueprint Khusus F&B bagian 8 & 10).
 *
 * - Bahan baku memakai satuan dasar (g / ml / pcs); satuan beli dikonversi ke satuan dasar.
 * - Stok per outlet; setiap perubahan tercatat di stock_movements (buku besar, dapat ditelusuri).
 * - Biaya bahan: rata-rata tertimbang (weighted average) per outlet.
 * - Resep: bahan dasar menu, tambahan per varian, dan tambahan per modifier.
 * - Pengurangan stok otomatis dari resep = pengaturan per outlet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('sku', 40)->nullable();
            $table->string('base_unit', 8);                              // g / ml / pcs
            $table->string('purchase_unit', 20)->nullable();             // mis. kg, liter, dus
            $table->decimal('purchase_unit_size', 14, 3)->nullable();    // isi satuan beli dalam satuan dasar
            $table->decimal('min_stock', 14, 3)->default(0);             // batas minimum (satuan dasar)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
            $table->unique(['business_id', 'sku']);
        });

        Schema::create('ingredient_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('avg_cost', 14, 4)->default(0);              // rupiah per satuan dasar
            $table->timestamps();

            $table->unique(['outlet_id', 'ingredient_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24)->index();
            $table->decimal('quantity', 14, 3);                          // + masuk, − keluar (satuan dasar)
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('balance_after', 14, 3);
            $table->nullableMorphs('reference');
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['outlet_id', 'ingredient_id', 'created_at']);
        });

        // Resep: baris dasar (menu_item_id), tambahan varian (+ menu_variant_id), atau tambahan modifier (modifier_id).
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('menu_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->timestamps();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->index();                       // draft / completed
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('system_quantity', 14, 3);
            $table->decimal('counted_quantity', 14, 3)->nullable();
            $table->decimal('difference', 14, 3)->nullable();
            $table->decimal('unit_cost', 14, 4)->default(0);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('to_outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4)->default(0);
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->boolean('recipe_stock_deduction')->default(false)->after('kitchen_use_ready');
        });

        // Estimasi biaya bahan (COGS) per item terjual, dihitung dari resep & HPP rata-rata outlet saat transaksi.
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_amount')->default(0)->after('line_total');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('cost_amount');
        });
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn('recipe_stock_deduction');
        });

        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('ingredient_stocks');
        Schema::dropIfExists('ingredients');
    }
};
