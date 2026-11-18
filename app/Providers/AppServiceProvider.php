<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Setiap Permission menjadi ability Gate: @can('menu.manage'), ->middleware('can:menu.manage').
        // Ability lain (mis. policy model) tetap diteruskan ke pengecekan normal (return null).
        Gate::before(function (User $user, string $ability) {
            $permission = Permission::tryFrom($ability);

            return $permission ? $user->hasPermission($permission) : null;
        });

        // Batasi percobaan login kasir via API: 5 per menit per email + IP.
        RateLimiter::for('pos-login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
    }
}
