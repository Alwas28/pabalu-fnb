<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\AuditLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Hak Akses Role')] class extends Component
{
    /**
     * Izin tercentang per role: [role => [nama case Permission => bool]].
     *
     * @var array<string, array<string, bool>>
     */
    public array $grants = [];

    public function mount(): void
    {
        abort_unless(config('features.role_permissions'), 404);
        $this->authorize('roles.manage');
        $this->loadGrants();
    }

    private function business()
    {
        return auth()->user()->activeBusiness();
    }

    private function loadGrants(): void
    {
        $business = $this->business();
        $this->grants = [];

        foreach ($this->roles() as $role) {
            $granted = array_map(fn (Permission $p) => $p->value, $business->permissionsFor($role));
            foreach ($this->editablePermissions() as $permission) {
                $this->grants[$role->value][$permission->name] = in_array($permission->value, $granted, true);
            }
        }
    }

    /** @return list<UserRole> */
    public function roles(): array
    {
        return array_values(array_filter(UserRole::cases(), fn (UserRole $r) => $r->isConfigurable()));
    }

    /** @return list<Permission> */
    public function editablePermissions(): array
    {
        return array_values(array_filter(Permission::cases(), fn (Permission $p) => ! in_array($p, UserRole::ownerOnlyPermissions(), true)));
    }

    public function resetRole(string $role): void
    {
        $this->authorize('roles.manage');
        $role = UserRole::from($role);

        foreach ($this->editablePermissions() as $permission) {
            $this->grants[$role->value][$permission->name] = $role->can($permission);
        }
    }

    public function save(): void
    {
        $this->authorize('roles.manage');
        $business = $this->business();
        $stored = [];
        $changes = [];

        foreach ($this->roles() as $role) {
            $selected = collect($this->editablePermissions())
                ->filter(fn (Permission $p) => (bool) ($this->grants[$role->value][$p->name] ?? false))
                ->map(fn (Permission $p) => $p->value)
                ->values()
                ->all();
            $default = array_values(array_map(fn (Permission $p) => $p->value, array_filter($role->permissions(), fn ($p) => ! in_array($p, UserRole::ownerOnlyPermissions(), true))));
            $current = array_map(fn (Permission $p) => $p->value, $business->permissionsFor($role));

            // Sama dengan bawaan → tidak perlu disimpan (tetap ikut pembaruan bawaan berikutnya).
            if (collect($selected)->sort()->values()->all() !== collect($default)->sort()->values()->all()) {
                $stored[$role->value] = $selected;
            }

            $added = array_diff($selected, $current);
            $removed = array_diff($current, $selected);
            if ($added || $removed) {
                $label = fn (array $values) => collect($values)->map(fn ($v) => Permission::from($v)->label())->join(', ') ?: '—';
                $changes[$role->label()] = ['dicabut: '.$label($removed), 'ditambah: '.$label($added)];
            }
        }

        $business->update(['role_permissions' => $stored ?: null]);
        AuditLog::record($business->id, $business, 'permissions', 'Hak akses role', $changes);

        foreach ($business->memberships()->with('user')->get() as $membership) {
            $membership->user?->forgetMembership();
        }

        $this->loadGrants();
        $this->dispatch('toast', type: 'success', message: $changes ? 'Hak akses role disimpan' : 'Tidak ada perubahan');
    }
}; ?>

@php
    $business = auth()->user()->activeBusiness();
    $groups = collect($this->editablePermissions())->groupBy(fn ($p) => $p->group());
    $roles = $this->roles();
@endphp

<x-slot name="actions">
    <a href="{{ route('settings.audit') }}" wire:navigate class="btn btn-secondary !px-3 sm:!px-[18px]"><i class="fa-solid fa-clock-rotate-left"></i><span class="hidden sm:inline">Riwayat Perubahan</span></a>
</x-slot>

<form wire:submit="save" class="flex flex-col gap-5">
    <div class="text-[12.5px] text-pb-muted max-w-3xl -mb-1">
        Centang izin yang dimiliki setiap role. Perubahan berlaku untuk semua anggota dengan role tersebut di <b>{{ $business->name }}</b>.
        <b>Owner</b> selalu memiliki semua izin. Persetujuan void/refund, payment gateway &amp; pengaturan hak akses tetap khusus owner.
    </div>

    <div class="card overflow-hidden animate-fadeUp">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="min-w-[240px]">Izin</th>
                        @foreach ($roles as $role)
                            <th class="text-center !normal-case !tracking-normal">
                                <div class="text-[12px] font-bold text-pb-text">{{ $role->label() }}</div>
                                @if ($business->hasCustomPermissions($role))
                                    <button type="button" wire:click="resetRole('{{ $role->value }}')" class="text-[10.5px] link font-normal">kembalikan bawaan</button>
                                @else
                                    <span class="text-[10.5px] text-pb-muted font-normal">bawaan</span>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups as $group => $permissions)
                        <tr><td colspan="{{ count($roles) + 1 }}" class="!py-2 !text-[11px] uppercase tracking-wider text-pb-muted bg-pb-surface2">{{ $group }}</td></tr>
                        @foreach ($permissions as $permission)
                            <tr wire:key="perm-{{ $permission->value }}">
                                <td class="td-main !whitespace-normal">{{ $permission->label() }}</td>
                                @foreach ($roles as $role)
                                    @php $default = $role->can($permission); @endphp
                                    <td class="text-center">
                                        <label class="inline-flex flex-col items-center gap-0.5 cursor-pointer">
                                            <input type="checkbox" wire:model.live="grants.{{ $role->value }}.{{ $permission->name }}" class="f-check"
                                                   aria-label="{{ $permission->label() }} untuk {{ $role->label() }}">
                                            @if (($grants[$role->value][$permission->name] ?? false) !== $default)
                                                <span class="text-[9.5px] a-text font-semibold">diubah</span>
                                            @endif
                                        </label>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex justify-end gap-2.5 pb-4">
        <x-primary-button wire:loading.attr="disabled" wire:target="save"><i class="fa-solid fa-check"></i>Simpan Hak Akses</x-primary-button>
    </div>
</form>
