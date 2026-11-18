<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\BusinessUser;
use App\Models\User;

/**
 * Aturan modul Karyawan (keanggotaan bisnis).
 */
class BusinessUserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageEmployees);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageEmployees)
            && $user->currentRole()?->assignableRoles() !== [];
    }

    /**
     * Mengubah role / outlet / status anggota.
     * - harus di bisnis aktif yang sama,
     * - tidak boleh mengubah keanggotaan sendiri,
     * - hanya anggota dengan role yang boleh dikelola oleh role pelaku.
     */
    public function update(User $user, BusinessUser $member): bool
    {
        $actor = $user->currentMembership();

        return $actor !== null
            && $user->hasPermission(Permission::ManageEmployees)
            && $member->business_id === $actor->business_id
            && $member->user_id !== $user->id
            && $actor->role->canAssign($member->role);
    }
}
