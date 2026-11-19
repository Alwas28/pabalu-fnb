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

    public const SUSPENDED_MESSAGE = 'Bisnis Anda sedang ditangguhkan oleh pengelola aplikasi. Hubungi tim Pabalu untuk informasi lebih lanjut.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->hasBusiness()) {
            return $next($request);
        }

        $deactivated = $user->memberships()->exists();
        $suspended = $user->memberships()->where('is_active', true)->whereHas('business', fn ($q) => $q->whereNotNull('suspended_at'))->exists();
        $message = $suspended ? self::SUSPENDED_MESSAGE : self::DEACTIVATED_MESSAGE;
        $isApi = $request->expectsJson() || $request->is('api/*');

        if ($isApi) {
            abort(403, $deactivated ? $message : 'Akun belum terdaftar di bisnis mana pun.');
        }

        // Super admin tidak tergabung di bisnis: arahkan ke panel admin.
        if (! $deactivated && $user->isSuperAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        if ($deactivated) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', $message);
        }

        return redirect()->route('onboarding');
    }
}
