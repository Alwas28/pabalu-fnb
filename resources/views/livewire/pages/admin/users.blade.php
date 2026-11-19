<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Admin\PlatformAdmin;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Pengguna')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /** '' = semua, 'owner', 'deactivated', 'super_admin' */
    #[Url]
    public string $filter = '';

    public string $reason = '';

    public ?int $targetUserId = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'filter'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function users()
    {
        return User::query()
            ->with(['memberships.business'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('email', 'like', '%'.$this->search.'%')))
            ->when($this->filter === 'owner', fn ($q) => $q->whereHas('memberships', fn ($q) => $q->where('role', UserRole::Owner)))
            ->when($this->filter === 'deactivated', fn ($q) => $q->whereNotNull('deactivated_at'))
            ->when($this->filter === 'super_admin', fn ($q) => $q->where('is_super_admin', true))
            ->orderByDesc('is_super_admin')
            ->latest()
            ->paginate(25);
    }

    public function openDeactivate(int $userId): void
    {
        $this->reset('reason');
        $this->resetValidation();
        $this->targetUserId = User::findOrFail($userId)->id;
        $this->dispatch('open-modal', name: 'user-deactivate');
    }

    public function deactivate(PlatformAdmin $admin): void
    {
        $this->resetValidation();

        $user = User::findOrFail($this->targetUserId);
        $admin->deactivateUser($user, $this->reason);

        $this->dispatch('close-modal', name: 'user-deactivate');
        $this->dispatch('toast', type: 'success', message: "Akun {$user->name} dinonaktifkan");
        unset($this->users);
    }

    public function activate(int $userId, PlatformAdmin $admin): void
    {
        $user = User::findOrFail($userId);
        $admin->activateUser($user);

        $this->dispatch('toast', type: 'success', message: "Akun {$user->name} aktif kembali");
        unset($this->users);
    }

    public function sendReset(int $userId, PlatformAdmin $admin): void
    {
        $this->dispatch('toast', type: 'info', message: $admin->sendPasswordReset(User::findOrFail($userId)));
    }

    #[Computed]
    public function target(): ?User
    {
        return $this->targetUserId ? User::find($this->targetUserId) : null;
    }
}; ?>

@php $tz = config('app.admin_timezone'); @endphp

<div class="flex flex-col gap-5">
    <div class="card overflow-hidden animate-fadeUp">
        <div class="card-header flex-wrap gap-3">
            <div class="card-title"><i class="fa-solid fa-users"></i>Semua Pengguna</div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-pb-muted text-xs"></i>
                    <input type="search" wire:model.live.debounce.300ms="search" class="f-input !py-1.5 !pl-8 !w-56 !text-[12.5px]" placeholder="Nama / email" aria-label="Cari pengguna">
                </div>
                <select wire:model.live="filter" class="f-input !py-1.5 !w-44 !text-[12.5px]" aria-label="Saring pengguna">
                    <option value="">Semua pengguna</option>
                    <option value="owner">Owner</option>
                    <option value="deactivated">Akun nonaktif</option>
                    <option value="super_admin">Super admin</option>
                </select>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Pengguna</th><th>Bisnis &amp; role</th><th>Status</th><th>Terdaftar</th><th></th></tr></thead>
                <tbody>
                    @forelse ($this->users as $user)
                        <tr wire:key="usr-{{ $user->id }}">
                            <td class="td-main">
                                {{ $user->name }}
                                @if ($user->isSuperAdmin())<span class="badge badge-blue ml-1">Super admin</span>@endif
                                <div class="text-[11px] text-pb-muted font-normal">{{ $user->email }}</div>
                            </td>
                            <td class="text-[12.5px]">
                                @forelse ($user->memberships as $membership)
                                    <div>
                                        <a href="{{ route('admin.businesses.show', $membership->business_id) }}" wire:navigate class="link">{{ $membership->business?->name }}</a>
                                        <span class="text-pb-muted">· {{ $membership->role->label() }}{{ $membership->is_active ? '' : ' (nonaktif)' }}</span>
                                        @if ($membership->business?->isSuspended())<span class="badge badge-red ml-1">Ditangguhkan</span>@endif
                                    </div>
                                @empty
                                    <span class="text-pb-muted">{{ $user->isSuperAdmin() ? 'Pengelola aplikasi' : 'Belum punya bisnis' }}</span>
                                @endforelse
                            </td>
                            <td>
                                @if ($user->isDeactivated())
                                    <span class="badge badge-red">Nonaktif</span>
                                    <div class="text-[11px] text-pb-muted mt-0.5 max-w-[220px]">{{ $user->deactivation_reason }}</div>
                                @else
                                    <span class="badge badge-green">Aktif</span>
                                @endif
                            </td>
                            <td class="text-[12.5px] whitespace-nowrap">{{ $user->created_at->timezone($tz)->format('d/m/Y') }}</td>
                            <td class="text-right whitespace-nowrap">
                                @unless ($user->isSuperAdmin())
                                    <button type="button" wire:click="sendReset({{ $user->id }})" wire:confirm="Kirim link reset password ke {{ $user->email }}?" class="btn-ghost" title="Kirim link reset password" aria-label="Kirim link reset password"><i class="fa-solid fa-key"></i></button>
                                    @if ($user->isDeactivated())
                                        <button type="button" wire:click="activate({{ $user->id }})" class="btn-ghost font-semibold">Aktifkan</button>
                                    @else
                                        <button type="button" wire:click="openDeactivate({{ $user->id }})" class="btn-ghost font-semibold" style="color:#f87171">Nonaktifkan</button>
                                    @endif
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center !py-8">Tidak ada pengguna yang cocok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->users->hasPages())
            <div class="px-5 py-3 border-t border-pb-border">{{ $this->users->links() }}</div>
        @endif
    </div>

    <x-modal name="user-deactivate" maxWidth="md" focusable>
        <form wire:submit="deactivate">
            <div class="px-6 py-5 border-b border-pb-border font-display text-base font-bold text-pb-text">Nonaktifkan {{ $this->target?->name }}</div>
            <div class="px-6 py-5 flex flex-col gap-3">
                <p class="text-[13px] text-pb-muted">
                    Akun langsung keluar dari web dan aplikasi kasir, dan tidak bisa login sampai diaktifkan kembali.
                    @if ($this->target?->ownsBusiness())
                        Akun ini owner; karyawan di bisnisnya tetap bisa bekerja. Untuk menghentikan seluruh bisnis, tangguhkan bisnisnya.
                    @endif
                </p>
                <div>
                    <x-input-label for="reason" value="Alasan" />
                    <x-text-input wire:model="reason" id="reason" placeholder="mis. pelanggaran ketentuan layanan" required />
                    <x-input-error :messages="$errors->get('reason')" class="mt-1.5" />
                    <x-input-error :messages="$errors->get('user')" class="mt-1.5" />
                </div>
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-danger-button wire:loading.attr="disabled" wire:target="deactivate"><i class="fa-solid fa-ban"></i>Nonaktifkan</x-danger-button>
            </div>
        </form>
    </x-modal>
</div>
