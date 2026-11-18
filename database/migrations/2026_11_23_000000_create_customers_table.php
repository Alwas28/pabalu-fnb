<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pelanggan (Blueprint Khusus F&B bagian 4): profil, riwayat order, catatan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->date('birthday')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'phone']);
            $table->index(['business_id', 'name']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('dining_table_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
        Schema::dropIfExists('customers');
    }
};
