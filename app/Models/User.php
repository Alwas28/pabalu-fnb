<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Permission;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Cache keanggotaan aktif selama satu request. */
    private ?BusinessUser $resolvedMembership = null;

    private bool $membershipResolved = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    /* ─────────────── Status akun platform ─────────────── */

    /** Pengelola aplikasi (panel /admin), bukan admin bisnis/outlet. */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /** Akun dinonaktifkan super admin: tidak bisa login web maupun aplikasi kasir. */
    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /** Pemilik minimal satu bisnis. */
    public function ownsBusiness(): bool
    {
        return $this->memberships()->where('role', UserRole::Owner)->exists();
    }

    /* ─────────────── Relasi ─────────────── */

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessUser::class);
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_users')
            ->using(BusinessUser::class)
            ->withPivot(['id', 'role', 'is_active'])
            ->withTimestamps();
    }

    public function currentBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'current_business_id');
    }

    /* ─────────────── Konteks bisnis ─────────────── */

    /**
     * Keanggotaan aktif pada bisnis yang sedang dipilih.
     * Bila belum ada pilihan (atau tidak valid), pilih keanggotaan aktif pertama.
     */
    public function currentMembership(): ?BusinessUser
    {
        if ($this->membershipResolved) {
            return $this->resolvedMembership;
        }

        // Bisnis yang ditangguhkan super admin tidak bisa dipakai oleh anggotanya.
        $memberships = $this->memberships()
            ->where('is_active', true)
            ->whereHas('business', fn ($q) => $q->whereNull('suspended_at'))
            ->with('business')
            ->get();

        $membership = $memberships->firstWhere('business_id', $this->current_business_id)
            ?? $memberships->first();

        if ($membership && $membership->business_id !== $this->current_business_id) {
            $this->forceFill(['current_business_id' => $membership->business_id])->saveQuietly();
        }

        $this->membershipResolved = true;

        return $this->resolvedMembership = $membership;
    }

    public function forgetMembership(): void
    {
        $this->membershipResolved = false;
        $this->resolvedMembership = null;
    }

    public function hasBusiness(): bool
    {
        return $this->currentMembership() !== null;
    }

    public function activeBusiness(): ?Business
    {
        return $this->currentMembership()?->business;
    }

    public function currentRole(): ?UserRole
    {
        return $this->currentMembership()?->role;
    }

    public function hasPermission(Permission $permission): bool
    {
        $membership = $this->currentMembership();

        return (bool) $membership?->business->roleHasPermission($membership->role, $permission);
    }

    /* ─────────────── Konteks outlet ─────────────── */

    /**
     * Outlet yang boleh diakses pada bisnis aktif.
     * Owner & Business Admin: semua outlet; role lain: outlet yang ditugaskan.
     *
     * @return Collection<int, Outlet>
     */
    public function accessibleOutlets(): Collection
    {
        $membership = $this->currentMembership();

        if (! $membership) {
            return new Collection;
        }

        return $membership->role->coversAllOutlets()
            ? $membership->business->outlets()->get()
            : $membership->outlets()->orderBy('name')->get();
    }

    public function canAccessOutlet(Outlet $outlet): bool
    {
        $membership = $this->currentMembership();

        if (! $membership || $outlet->business_id !== $membership->business_id) {
            return false;
        }

        return $membership->role->coversAllOutlets()
            || $membership->outlets()->whereKey($outlet->getKey())->exists();
    }

    /**
     * Outlet aktif: pilihan terakhir pengguna bila masih boleh diakses,
     * jika tidak, outlet aktif pertama yang dapat diakses.
     */
    public function currentOutlet(): ?Outlet
    {
        $outlets = $this->accessibleOutlets();

        return $outlets->firstWhere('id', $this->current_outlet_id)
            ?? $outlets->firstWhere('is_active', true)
            ?? $outlets->first();
    }

    public function switchOutlet(Outlet $outlet): bool
    {
        if (! $this->canAccessOutlet($outlet)) {
            return false;
        }

        $this->forceFill(['current_outlet_id' => $outlet->id])->save();

        return true;
    }
}
