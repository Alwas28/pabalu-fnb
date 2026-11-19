<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Pengaturan seluruh aplikasi yang dikelola super admin (key → nilai JSON).
 * Nilai di-cache selama satu jam dan dibersihkan setiap kali disimpan.
 */
class PlatformSetting extends Model
{
    public const ROLE_PERMISSIONS = 'role_permissions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::remember("platform-setting:{$key}", 3600, fn () => self::where('key', $key)->value('value'));

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return $value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("platform-setting:{$key}");
    }
}
