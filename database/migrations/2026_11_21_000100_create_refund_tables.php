<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Void & refund pesanan yang sudah dibayar (Blueprint Khusus F&B bagian 4, 7, 14).
 *
 * - refund_requests: pengajuan staf + keputusan owner (pelaku, waktu, alasan tercatat).
 * - order_refunds: uang yang benar-benar dikembalikan; tunai mengurangi kas laci shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8);                       // void / refund
            $table->unsignedBigInteger('amount');
            $table->string('method', 24)->nullable();        // metode pengembalian (refund); void mengikuti pembayaran asal
            $table->string('reason', 255);
            $table->string('status', 12)->index();           // pending / approved / rejected
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('order_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('refund_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 24);
            $table->unsignedBigInteger('amount');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('refunded_amount')->default(0)->after('change_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('refunded_amount');
        });

        Schema::dropIfExists('order_refunds');
        Schema::dropIfExists('refund_requests');
    }
};
