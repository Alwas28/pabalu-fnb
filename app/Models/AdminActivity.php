<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Jejak tindakan super admin: menonaktifkan akun, menangguhkan bisnis, mengubah hak akses role, dll.
 */
class AdminActivity extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function record(string $action, ?Model $subject, string $label, ?array $details = null): self
    {
        return self::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'label' => mb_substr($label, 0, 160),
            'details' => $details,
            'created_at' => now(),
        ]);
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'user.deactivated' => 'Akun dinonaktifkan',
            'user.activated' => 'Akun diaktifkan',
            'user.password_reset' => 'Link reset password dikirim',
            'business.suspended' => 'Bisnis ditangguhkan',
            'business.reactivated' => 'Bisnis diaktifkan kembali',
            'roles.updated' => 'Hak akses role diubah',
            'expense_category.created' => 'Kategori pengeluaran ditambah',
            'expense_category.renamed' => 'Kategori pengeluaran diganti nama',
            'expense_category.deactivated' => 'Kategori pengeluaran dinonaktifkan',
            'expense_category.activated' => 'Kategori pengeluaran diaktifkan',
            default => $this->action,
        };
    }

    public function actionBadge(): string
    {
        return match ($this->action) {
            'user.deactivated', 'business.suspended', 'expense_category.deactivated' => 'badge-red',
            'user.activated', 'business.reactivated', 'expense_category.created', 'expense_category.activated' => 'badge-green',
            default => 'badge-blue',
        };
    }
}
