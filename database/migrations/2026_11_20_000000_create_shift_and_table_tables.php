<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 3 — Shift & operasional (Blueprint Khusus F&B bagian 5–7 & 10).
 *
 * - cash_shifts: shift kasir (wajib dibuka sebelum transaksi), modal awal, kas aktual & selisih.
 * - cash_movements: kas masuk / keluar di luar penjualan selama shift.
 * - dining_tables: meja per outlet; pelacakan status terisi bersifat opsional per outlet.
 * - Order dine-in dapat "bayar nanti" (open bill) bila outlet mengizinkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // kasir pemilik shift
            $table->string('status', 16)->index();
            $table->unsignedBigInteger('opening_cash');
            $table->timestamp('opened_at');
            $table->string('opening_note', 255)->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('expected_cash')->nullable();
            $table->unsignedBigInteger('actual_cash')->nullable();
            $table->bigInteger('cash_difference')->nullable();          // aktual − ekspektasi
            $table->string('closing_note', 500)->nullable();
            $table->json('summary')->nullable();                         // rekap dibekukan saat tutup
            $table->timestamps();

            $table->index(['outlet_id', 'user_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 8);                  // in / out
            $table->unsignedBigInteger('amount');
            $table->string('reason', 255);
            $table->timestamps();
        });

        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('area', 60)->nullable();
            $table->string('name', 20);                  // nomor / nama meja, mis. "A1"
            $table->unsignedTinyInteger('capacity')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('occupied_at')->nullable(); // dipakai bila pelacakan status aktif
            $table->timestamps();

            $table->unique(['outlet_id', 'name']);
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->boolean('dine_in_pay_later')->default(false)->after('tables_enabled');
            $table->boolean('table_status_enabled')->default(false)->after('dine_in_pay_later');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('cash_shift_id')->nullable()->after('cashier_id')->constrained()->nullOnDelete();
            $table->foreignId('dining_table_id')->nullable()->after('table_number')->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable()->after('cancelled_by');
        });

        Schema::table('order_payments', function (Blueprint $table) {
            $table->foreignId('cash_shift_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_shift_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('dining_table_id');
            $table->dropConstrainedForeignId('cash_shift_id');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['dine_in_pay_later', 'table_status_enabled']);
        });

        Schema::dropIfExists('dining_tables');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_shifts');
    }
};
