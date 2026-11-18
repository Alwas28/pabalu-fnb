<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Halaman awal langsung ke dashboard (tamu otomatis diarahkan ke halaman login).
Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::view('profile', 'profile')->name('profile');

    // Pengguna baru (belum punya bisnis) membuat bisnis & outlet pertamanya.
    Volt::route('onboarding', 'pages.onboarding')->name('onboarding');

    Route::middleware(['verified', 'business'])->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        // Pengaturan — Bisnis & Outlet
        Route::middleware('can:settings.manage')->prefix('settings')->name('settings.')->group(function () {
            Volt::route('business', 'pages.settings.business')->name('business');
            Volt::route('outlets/create', 'pages.settings.outlet-form')->name('outlets.create');
            Volt::route('outlets/{outlet}', 'pages.settings.outlet-form')->name('outlets.edit');
        });
    });
});

require __DIR__.'/auth.php';
