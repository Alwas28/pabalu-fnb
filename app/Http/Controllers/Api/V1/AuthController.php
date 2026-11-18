<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MeResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login kasir → token API (ability "pos").
     *
     * Hanya akun dengan izin pos.use pada bisnis aktifnya yang dapat login.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (! $user->hasBusiness()) {
            abort(403, 'Akun belum terdaftar di bisnis mana pun.');
        }

        if (! $user->hasPermission(Permission::UsePos)) {
            abort(403, 'Akun ini tidak memiliki akses kasir.');
        }

        $token = $user->createToken($data['device_name'], ['pos']);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'data' => new MeResource($user),
        ], 201);
    }

    /** Cabut token yang sedang dipakai (perangkat ini saja). */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    /** Profil pengguna, bisnis aktif, role & izin. */
    public function me(Request $request): MeResource
    {
        return new MeResource($request->user());
    }
}
