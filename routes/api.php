<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OutletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Kasir (POS) — v1
|--------------------------------------------------------------------------
| Autentikasi: Bearer token (Laravel Sanctum) dengan ability "pos".
| Semua endpoint dibatasi pada bisnis aktif & outlet yang ditugaskan.
| Nominal uang dalam rupiah (integer). Endpoint shift ditambahkan bersama modulnya.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:pos-login')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', 'ability:pos', 'business', 'can:pos.use'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::get('outlets', [OutletController::class, 'index'])->name('outlets.index');
        Route::get('outlets/{outlet}', [OutletController::class, 'show'])->name('outlets.show');

        Route::get('outlets/{outlet}/menu', [MenuController::class, 'index'])->name('outlets.menu');
        Route::get('outlets/{outlet}/orders', [OrderController::class, 'index'])->name('outlets.orders.index');
        Route::post('outlets/{outlet}/orders', [OrderController::class, 'store'])->name('outlets.orders.store');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::get('orders/{order}/receipt', [OrderController::class, 'receipt'])->name('orders.receipt');
    });
});
