<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\OfflineController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OutletController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\TableController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Kasir (POS) — v1
|--------------------------------------------------------------------------
| Autentikasi: Bearer token (Laravel Sanctum) dengan ability "pos".
| Semua endpoint dibatasi pada bisnis aktif & outlet yang ditugaskan.
| Nominal uang dalam rupiah (integer). Transaksi membutuhkan shift kasir yang sedang berjalan.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:pos-login')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', 'active', 'ability:pos', 'business', 'can:pos.use'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::get('outlets', [OutletController::class, 'index'])->name('outlets.index');
        Route::get('outlets/{outlet}', [OutletController::class, 'show'])->name('outlets.show');

        Route::get('outlets/{outlet}/menu', [MenuController::class, 'index'])->name('outlets.menu');
        Route::get('outlets/{outlet}/orders', [OrderController::class, 'index'])->name('outlets.orders.index');
        Route::post('outlets/{outlet}/orders', [OrderController::class, 'store'])->name('outlets.orders.store');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::get('orders/{order}/receipt', [OrderController::class, 'receipt'])->name('orders.receipt');
        Route::get('orders/{order}/kitchen-ticket', [OrderController::class, 'kitchenTicket'])->name('orders.kitchen-ticket');

        // Open bill (dine-in bayar nanti)
        Route::post('orders/{order}/items', [OrderController::class, 'addItems'])->name('orders.items.store');
        Route::post('orders/{order}/settle', [OrderController::class, 'settle'])->name('orders.settle');
        Route::post('orders/{order}/items/{item}/void', [OrderController::class, 'voidItem'])->scopeBindings()->name('orders.items.void');
        Route::post('orders/{order}/merge', [OrderController::class, 'merge'])->name('orders.merge');
        Route::post('orders/{order}/move-table', [OrderController::class, 'moveTable'])->name('orders.move-table');
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        Route::post('orders/{order}/refund-requests', [OrderController::class, 'requestRefund'])->name('orders.refund-requests.store');

        // Shift kasir
        Route::get('outlets/{outlet}/shift', [ShiftController::class, 'current'])->name('outlets.shift.current');
        Route::post('outlets/{outlet}/shifts', [ShiftController::class, 'open'])->name('outlets.shifts.store');
        Route::get('shifts/{shift}', [ShiftController::class, 'show'])->name('shifts.show');
        Route::post('shifts/{shift}/cash-movements', [ShiftController::class, 'movement'])->name('shifts.movements.store');
        Route::post('shifts/{shift}/close', [ShiftController::class, 'close'])->name('shifts.close');

        // Pelanggan
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');

        // Meja
        Route::get('outlets/{outlet}/tables', [TableController::class, 'index'])->name('outlets.tables.index');
        Route::post('tables/{table}/clear', [TableController::class, 'clear'])->name('tables.clear');

        // Mode offline (aplikasi Android)
        Route::post('outlets/{outlet}/pos-devices', [OfflineController::class, 'register'])->name('outlets.pos-devices.store');
        Route::get('pos-devices/{device}/bundle', [OfflineController::class, 'bundle'])->name('pos-devices.bundle');
        Route::post('pos-devices/{device}/sync', [OfflineController::class, 'sync'])->middleware('throttle:60,1')->name('pos-devices.sync');
    });
});
