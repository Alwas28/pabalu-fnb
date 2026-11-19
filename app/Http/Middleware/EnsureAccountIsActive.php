<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keluarkan akun yang dinonaktifkan super admin, termasuk sesi & token yang masih berjalan.
 */
class EnsureAccountIsActive
{
    public const MESSAGE = 'Akun Anda telah dinonaktifkan oleh pengelola aplikasi. Hubungi tim Pabalu untuk informasi lebih lanjut.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isDeactivated()) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            abort(403, self::MESSAGE);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', self::MESSAGE);
    }
}
