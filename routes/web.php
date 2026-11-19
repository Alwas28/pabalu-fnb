<?php

use App\Http\Controllers\KitchenTicketController;
use App\Http\Controllers\ReceiptController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Halaman awal langsung ke dashboard (tamu otomatis diarahkan ke halaman login).
Route::redirect('/', '/dashboard');

// Struk digital publik (dibuka dari QR code di struk cetak).
Route::get('r/{token}', [ReceiptController::class, 'show'])
    ->middleware('throttle:60,1')
    ->where('token', '[A-Za-z0-9]{32}')
    ->name('receipts.show');

// Panel super admin (pengelola aplikasi) — terpisah dari aplikasi bisnis.
Route::middleware(['auth', 'super-admin'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('/', 'pages.admin.dashboard')->name('dashboard');
    Volt::route('businesses', 'pages.admin.businesses.index')->name('businesses.index');
    Volt::route('businesses/{business}', 'pages.admin.businesses.show')->name('businesses.show');
    Volt::route('users', 'pages.admin.users')->name('users.index');
    Volt::route('roles', 'pages.admin.roles')->name('roles');
    Volt::route('expense-categories', 'pages.admin.expense-categories')->name('expense-categories');
    Volt::route('activity', 'pages.admin.activity')->name('activity');
    Route::view('api-docs', 'admin.api-docs')->name('api-docs');
});

Route::middleware('auth')->group(function () {
    Route::view('profile', 'profile')->name('profile');

    // Pengguna baru (belum punya bisnis) membuat bisnis & outlet pertamanya.
    Volt::route('onboarding', 'pages.onboarding')->name('onboarding');

    Route::middleware(['verified', 'business'])->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        // Kasir (POS) & pesanan
        Volt::route('pos', 'pages.pos.index')->middleware('can:pos.use')->name('pos');
        Route::middleware('can:orders.view')->group(function () {
            Volt::route('orders', 'pages.orders.index')->name('orders.index');
            Volt::route('orders/{order}', 'pages.orders.show')->name('orders.show');
        });

        // Persetujuan void / refund (owner)
        Volt::route('approvals', 'pages.approvals.index')->middleware('can:refunds.approve')->name('approvals.index');

        // Antrean dapur & tiket dapur
        Volt::route('kitchen', 'pages.kitchen.index')->middleware('can:kitchen.view')->name('kitchen.index');
        Route::get('orders/{order}/kitchen-ticket', [KitchenTicketController::class, 'show'])->name('orders.kitchen-ticket');

        // Shift kasir & meja
        Route::middleware('can:shifts.manage')->group(function () {
            Volt::route('shifts', 'pages.shifts.index')->name('shifts.index');
            Volt::route('shifts/{shift}', 'pages.shifts.show')->name('shifts.show');
        });
        Volt::route('tables', 'pages.tables.index')->middleware('can:tables.manage')->name('tables.index');

        // Menu — daftar menu, kategori, modifier
        Route::middleware('can:menu.manage')->prefix('menu')->name('menu.')->group(function () {
            Volt::route('/', 'pages.menu.items.index')->name('items.index');
            Volt::route('items/create', 'pages.menu.items.form')->name('items.create');
            Volt::route('items/{menuItem}', 'pages.menu.items.form')->name('items.edit');
            Volt::route('categories', 'pages.menu.categories')->name('categories');
            Volt::route('modifiers', 'pages.menu.modifiers')->name('modifiers');
        });

        // Resep & bahan baku, stok bahan
        Volt::route('recipes', 'pages.recipes.index')->middleware('can:recipes.manage')->name('recipes.index');
        Route::middleware('can:inventory.manage')->prefix('inventory')->name('inventory.')->group(function () {
            Volt::route('/', 'pages.inventory.index')->name('index');
            Volt::route('counts', 'pages.inventory.counts')->name('counts.index');
        });

        // Keuangan & laporan
        Volt::route('finance', 'pages.finance.index')->middleware('can:finance.view')->name('finance.index');
        Volt::route('expenses', 'pages.expenses.index')->middleware('can:finance.view')->name('expenses.index');
        Volt::route('reports', 'pages.reports.index')->middleware('can:reports.view')->name('reports.index');

        // Pelanggan
        Volt::route('customers', 'pages.customers.index')->middleware('can:customers.manage')->name('customers.index');

        // Pembelian & supplier
        Route::middleware('can:purchasing.manage')->prefix('purchasing')->name('purchasing.')->group(function () {
            Volt::route('/', 'pages.purchasing.index')->name('index');
            Volt::route('suppliers', 'pages.purchasing.suppliers')->name('suppliers');
            Volt::route('orders/create', 'pages.purchasing.form')->name('create');
            Volt::route('orders/{purchaseOrder}/edit', 'pages.purchasing.form')->name('edit');
            Volt::route('orders/{purchaseOrder}', 'pages.purchasing.show')->name('show');
        });

        // Karyawan — anggota tim, role & penugasan outlet
        Volt::route('employees', 'pages.employees.index')
            ->middleware('can:employees.manage')
            ->name('employees.index');

        // Pengaturan — Bisnis & Outlet
        Route::middleware('can:settings.manage')->prefix('settings')->name('settings.')->group(function () {
            Volt::route('business', 'pages.settings.business')->name('business');
            Volt::route('outlets/create', 'pages.settings.outlet-form')->name('outlets.create');
            Volt::route('outlets/{outlet}', 'pages.settings.outlet-form')->name('outlets.edit');
            Volt::route('audit', 'pages.settings.audit')->name('audit');
        });

        // Hak akses role — khusus owner
        Volt::route('settings/roles', 'pages.settings.roles')->middleware('can:roles.manage')->name('settings.roles');
    });
});

require __DIR__.'/auth.php';
