<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori pengeluaran bawaan yang dikelola super admin untuk semua bisnis.
 * - expense_category_templates: daftar bawaan (nonaktif = tidak ditawarkan lagi, riwayat tetap utuh).
 * - expense_categories.template_id: kategori bisnis yang berasal dari daftar bawaan; null = dibuat owner.
 */
return new class extends Migration
{
    private const DEFAULTS = ['Bahan Baku', 'Gaji & Upah', 'Sewa Tempat', 'Listrik & Air', 'Gas & Bahan Bakar', 'Pemasaran', 'Perawatan & Perbaikan', 'Lain-lain'];

    public function up(): void
    {
        Schema::create('expense_category_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->after('business_id')
                ->constrained('expense_category_templates')->nullOnDelete();
        });

        $now = now();

        foreach (self::DEFAULTS as $name) {
            $templateId = DB::table('expense_category_templates')->insertGetId(['name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

            // Kategori bawaan yang sudah ada di tiap bisnis ditautkan ke daftar baru.
            DB::table('expense_categories')->where('name', $name)->update(['template_id' => $templateId]);
        }
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
        });

        Schema::dropIfExists('expense_category_templates');
    }
};
