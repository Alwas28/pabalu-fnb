<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super admin platform (pengelola aplikasi, bukan admin bisnis/outlet):
 * - users.is_super_admin: akses panel /admin.
 * - users.deactivated_at / deactivation_reason: akun dinonaktifkan super admin (tidak bisa login web & API).
 * - businesses.suspended_at / suspension_reason: bisnis ditangguhkan (semua anggotanya terblokir).
 * - platform_settings: pengaturan seluruh aplikasi (mis. izin bawaan setiap role).
 * - admin_activities: jejak tindakan super admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->timestamp('deactivated_at')->nullable()->after('is_super_admin');
            $table->string('deactivation_reason', 255)->nullable()->after('deactivated_at');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 255)->nullable();
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->nullableMorphs('subject');
            $table->string('label', 160);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activities');
        Schema::dropIfExists('platform_settings');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_super_admin', 'deactivated_at', 'deactivation_reason']);
        });
    }
};
