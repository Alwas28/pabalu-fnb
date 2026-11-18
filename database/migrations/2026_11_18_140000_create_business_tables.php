<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 1 — Fondasi: Owner → Business → Outlet, serta keanggotaan user di bisnis.
 * (Blueprint Umum bagian 4, Blueprint Khusus F&B bagian 3 & 4)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('business_type', 32)->default('fnb');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('timezone', 64)->default('Asia/Makassar');
            $table->string('currency', 3)->default('IDR');

            // Payment gateway bersifat opsional.
            $table->boolean('payment_gateway_enabled')->default(false);
            $table->string('payment_gateway_provider', 32)->nullable();
            $table->boolean('payment_gateway_sandbox')->default(true);
            $table->text('payment_gateway_credentials')->nullable(); // terenkripsi

            $table->timestamps();
        });

        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_active')->default(true);

            // Pengaturan operasional (dapat diaktifkan/nonaktifkan owner per outlet).
            $table->json('service_types');               // list ServiceType
            $table->boolean('tables_enabled')->default(false);
            $table->string('kitchen_mode', 16)->default('ticket');
            $table->boolean('tax_enabled')->default(false);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('service_charge_enabled')->default(false);
            $table->decimal('service_charge_rate', 5, 2)->default(0);
            $table->boolean('prices_include_tax')->default(false);
            $table->json('payment_methods');             // list PaymentMethod

            $table->timestamps();

            $table->unique(['business_id', 'code']);
        });

        Schema::create('business_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'user_id']);
        });

        Schema::create('business_user_outlet', function (Blueprint $table) {
            $table->foreignId('business_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->primary(['business_user_id', 'outlet_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_business_id')->nullable()->after('email')->constrained('businesses')->nullOnDelete();
            $table->foreignId('current_outlet_id')->nullable()->after('current_business_id')->constrained('outlets')->nullOnDelete();
        });

        // Role kini melekat pada keanggotaan bisnis (business_users), bukan pada user.
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('owner')->after('email')->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_outlet_id');
            $table->dropConstrainedForeignId('current_business_id');
        });

        Schema::dropIfExists('business_user_outlet');
        Schema::dropIfExists('business_users');
        Schema::dropIfExists('outlets');
        Schema::dropIfExists('businesses');
    }
};
