<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pastikan pengguna memiliki keanggotaan aktif di sebuah bisnis.
 * Web: arahkan ke onboarding. API: 403.
 */
class EnsureBusinessContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasBusiness()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                abort(403, 'Akun belum terdaftar di bisnis mana pun.');
            }

            return redirect()->route('onboarding');
        }

        return $next($request);
    }
}
