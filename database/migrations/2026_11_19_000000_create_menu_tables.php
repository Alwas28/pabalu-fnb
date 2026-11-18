<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 2 — Menu: kategori, menu, varian, modifier, serta harga & ketersediaan per outlet.
 * (Blueprint Khusus F&B bagian 4, 8 & 10)
 *
 * Semua nominal uang disimpan sebagai bilangan bulat rupiah.
 * Resep/BOM (pengurangan stok otomatis) ditunda: tabel recipes akan merujuk menu_items / menu_variants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('sku', 40)->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedBigInteger('price');           // harga dasar (berlaku bila outlet tidak menimpa)
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);   // nonaktif = diarsipkan, tidak tampil di kasir
            $table->timestamps();

            $table->unique(['business_id', 'sku']);
            $table->index(['business_id', 'is_active']);
        });

        // Varian (mis. ukuran): selisih harga terhadap harga menu di outlet.
        Schema::create('menu_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->bigInteger('price_adjustment')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Grup modifier (mis. "Level Gula", "Topping") dipakai ulang di banyak menu.
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedTinyInteger('min_select')->default(0);   // 0 = opsional
            $table->unsignedTinyInteger('max_select')->nullable();   // null = tanpa batas
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedBigInteger('price')->default(0);
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('menu_item_modifier_group', function (Blueprint $table) {
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->primary(['menu_item_id', 'modifier_group_id']);
        });

        // Pengaturan menu per outlet. Tanpa baris = tersedia dengan harga dasar.
        Schema::create('menu_item_outlet', function (Blueprint $table) {
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('price')->nullable();   // null = pakai harga dasar
            $table->boolean('is_available')->default(true);    // false = habis / tidak dijual di outlet ini
            $table->timestamps();
            $table->primary(['menu_item_id', 'outlet_id']);
        });

        // Pengaturan struk per outlet.
        Schema::table('outlets', function (Blueprint $table) {
            $table->unsignedTinyInteger('receipt_paper_width')->default(58)->after('payment_methods'); // 58 / 80 mm
            $table->string('receipt_footer', 255)->nullable()->after('receipt_paper_width');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['receipt_paper_width', 'receipt_footer']);
        });

        Schema::dropIfExists('menu_item_outlet');
        Schema::dropIfExists('menu_item_modifier_group');
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('menu_variants');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menu_categories');
    }
};
