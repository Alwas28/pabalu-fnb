<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\PlatformSetting;
use App\Support\Admin\PlatformAdmin;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Hak Akses Role')] class extends Component
{
    /**
     * Izin tercentang per role: [role => [nama case Permission => bool]].
     *
     * @var array<string, array<string, bool>>
     */
    public array $grants = [];

    public function mount(): void
    {
        $this->loadGrants();
    }

    private function loadGrants(): void
    {
        $stored = PlatformSetting::get(PlatformSetting::ROLE_PERMISSIONS, []);
        $this->grants = [];

        foreach (PlatformAdmin::configurableRoles() as $role) {
            $granted = $stored[$role->value] ?? PlatformAdmin::defaultPermissions($role);

            foreach (PlatformAdmin::grantablePermissions() as $permission) {
                $this->grants[$role->value][$permission->name] = in_array($permission->value, $granted, true);
            }
        }
    }

    public function isCustomized(string $role): bool
    {
        return isset(PlatformSetting::get(PlatformSetting::ROLE_PERMISSIONS, [])[$role]);
    }

    public function resetRole(string $role): void
    {
        $role = UserRole::from($role);
        $defaults = PlatformAdmin::defaultPermissions($role);

        foreach (PlatformAdmin::grantablePermissions() as $permission) {
            $this->grants[$role->value][$permission->name] = in_array($permission->value, $defaults, true);
        }
    }

    public function save(PlatformAdmin $admin): void
    {
        $selected = [];

        foreach (PlatformAdmin::configurableRoles() as $role) {
            $selected[$role->value] = collect(PlatformAdmin::grantablePermissions())
                ->filter(fn (Permission $p) => (bool) ($this->grants[$role->value][$p->name] ?? false))
                ->map(fn (Permission $p) => $p->value)
                ->values()->all();
        }

        $admin->saveRolePermissions($selected);
        $this->loadGrants();
        $this->dispatch('toast', type: 'success', message: 'Hak akses role disimpan untuk seluruh aplikasi');
    }
}; ?>

@php
    $groups = collect(\App\Support\Admin\PlatformAdmin::grantablePermissions())->groupBy(fn ($p) => $p->group());
    $roles = \App\Support\Admin\PlatformAdmin::configurableRoles();
@endphp

<form wire:submit="save" class="flex flex-col gap-5">
    <div class="text-[12.5px] text-pb-muted max-w-3xl -mb-1">
        Izin bawaan setiap role untuk <b>semua bisnis</b> di Pabalu. Perubahan langsung berlaku bagi seluruh karyawan dengan role tersebut, di web dan aplikasi kasir.
        <b>Owner</b> selalu memegang semua izin. Persetujuan void/refund, payment gateway &amp; pengaturan hak akses tetap khusus owner.
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
                                @if ($this->isCustomized($role->value))
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
                        <tr><td colspan="{{ count($roles) + 1 }}" class="!py-2 text-[11px] uppercase tracking-wider font-bold text-pb-muted bg-pb-surface2">{{ $group }}</td></tr>
                        @foreach ($permissions as $permission)
                            <tr wire:key="perm-{{ $permission->name }}">
                                <td class="td-main">{{ $permission->label() }}<div class="text-[11px] text-pb-muted font-normal font-mono">{{ $permission->value }}</div></td>
                                @foreach ($roles as $role)
                                    <td class="text-center">
                                        <input type="checkbox" wire:model="grants.{{ $role->value }}.{{ $permission->name }}"
                                               class="f-check"
                                               aria-label="{{ $permission->label() }} untuk {{ $role->label() }}">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex justify-end">
        <x-primary-button wire:loading.attr="disabled" wire:target="save"><i class="fa-solid fa-check"></i>Simpan Hak Akses</x-primary-button>
    </div>
</form>
