<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pastikan pengguna memiliki keanggotaan aktif di sebuah bisnis.
 *
 * - Belum pernah tergabung di bisnis → web: onboarding, API: 403.
 * - Semua keanggotaannya dinonaktifkan → web: keluar & kembali ke login, API: 403.
 */
class EnsureBusinessContext
{
    public const DEACTIVATED_MESSAGE = 'Akses Anda ke bisnis telah dinonaktifkan. Hubungi pemilik atau admin bisnis.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->hasBusiness()) {
            return $next($request);
        }

        $deactivated = $user->memberships()->exists();
        $isApi = $request->expectsJson() || $request->is('api/*');

        if ($isApi) {
            abort(403, $deactivated ? self::DEACTIVATED_MESSAGE : 'Akun belum terdaftar di bisnis mana pun.');
        }

        if ($deactivated) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', self::DEACTIVATED_MESSAGE);
        }

        return redirect()->route('onboarding');
    }
}
