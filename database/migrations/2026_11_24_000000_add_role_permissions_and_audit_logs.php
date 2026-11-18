<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - businesses.role_permissions: matriks izin per role yang diatur owner ({role: [permission, …]}).
 *   Role yang tidak ada di sini memakai izin bawaan (UserRole::permissions()).
 * - audit_logs: jejak perubahan data sensitif (harga, resep, pengaturan, hak akses) —
 *   pelaku, waktu, nilai lama & baru (Blueprint Khusus F&B bagian 14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('role_permissions')->nullable()->after('payment_gateway_credentials');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('auditable');
            $table->string('event', 32);                 // created / updated / deleted / custom
            $table->string('subject', 160);              // label yang mudah dibaca, mis. "Kopi Susu Gula Aren"
            $table->json('changes')->nullable();         // {field: [lama, baru]}
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('role_permissions');
        });
    }
};
