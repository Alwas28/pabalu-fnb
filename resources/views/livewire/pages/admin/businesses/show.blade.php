<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AdminActivity;
use App\Models\Business;
use App\Models\User;
use App\Support\Admin\PlatformAdmin;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] class extends Component
{
    public Business $business;

    /** Alasan menangguhkan bisnis / menonaktifkan akun. */
    public string $reason = '';

    public ?int $targetUserId = null;

    public function mount(Business $business): void
    {
        $this->business = $business;
    }

    public function rendering($view): void
    {
        $view->title($this->business->name);
    }

    /** @return array{orders: int, revenue: int, orders_all: int} */
    #[Computed]
    public function stats(): array
    {
        $paid = $this->business->orders()->reorder()
            ->where('status', '!=', OrderStatus::Cancelled)
            ->where('payment_status', '!=', PaymentStatus::Pending);

        $row = (clone $paid)->where('placed_at', '>=', now()->subDays(30))
            ->selectRaw('count(*) as orders, coalesce(sum(total - refunded_amount), 0) as revenue')->first();

        return ['orders' => (int) $row->orders, 'revenue' => (int) $row->revenue, 'orders_all' => (clone $paid)->count()];
    }

    #[Computed]
    public function outlets()
    {
        return $this->business->outlets()->withCount('posDevices')->get();
    }

    #[Computed]
    public function members()
    {
        return $this->business->memberships()->with('user')->get()->sortBy(fn ($m) => [$m->role->value !== 'owner', $m->user?->name])->values();
    }

    #[Computed]
    public function activities()
    {
        $userIds = $this->business->memberships()->pluck('user_id');

        return AdminActivity::with('user')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('subject_type', $this->business->getMorphClass())->where('subject_id', $this->business->id))
                ->orWhere(fn ($q) => $q->where('subject_type', (new User)->getMorphClass())->whereIn('subject_id', $userIds)))
            ->latest('created_at')->latest('id')->limit(10)->get();
    }

    public function openSuspend(): void
    {
        $this->reset('reason', 'targetUserId');
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'admin-reason');
    }

    public function openDeactivate(int $userId): void
    {
        $this->reset('reason');
        $this->resetValidation();
        $this->targetUserId = $this->memberUser($userId)->id;
        $this->dispatch('open-modal', name: 'admin-reason');
    }

    public function confirm(PlatformAdmin $admin): void
    {
        $this->resetValidation();

        if ($this->targetUserId) {
            $user = $this->memberUser($this->targetUserId);
            $admin->deactivateUser($user, $this->reason);
            $message = "Akun {$user->name} dinonaktifkan";
        } else {
            $admin->suspendBusiness($this->business, $this->reason);
            $message = "{$this->business->name} ditangguhkan";
        }

        $this->dispatch('close-modal', name: 'admin-reason');
        $this->dispatch('toast', type: 'success', message: $message);
        $this->business->refresh();
        unset($this->members, $this->activities);
    }

    public function reactivate(PlatformAdmin $admin): void
    {
        $admin->reactivateBusiness($this->business);
        $this->business->refresh();
        unset($this->activities);
        $this->dispatch('toast', type: 'success', message: "{$this->business->name} aktif kembali");
    }

    public function activateUser(int $userId, PlatformAdmin $admin): void
    {
        $user = $this->memberUser($userId);
        $admin->activateUser($user);
        unset($this->members, $this->activities);
        $this->dispatch('toast', type: 'success', message: "Akun {$user->name} aktif kembali");
    }

    public function sendReset(int $userId, PlatformAdmin $admin): void
    {
        $user = $this->memberUser($userId);
        $this->dispatch('toast', type: 'info', message: $admin->sendPasswordReset($user));
    }

    private function memberUser(int $userId): User
    {
        $membership = $this->business->memberships()->where('user_id', $userId)->first();
        abort_unless($membership?->user, 404);

        return $membership->user;
    }
}; ?>

@php $tz = config('app.admin_timezone'); $s = $this->stats; @endphp

<x-slot name="actions">
    @if ($business->isSuspended())
        <button type="button" wire:click="reactivate" wire:confirm="Aktifkan kembali {{ $business->name }}? Semua anggotanya bisa memakai aplikasi lagi." class="btn btn-primary !px-3 sm:!px-[18px]">
            <i class="fa-solid fa-play"></i><span class="hidden sm:inline">Aktifkan Bisnis</span>
        </button>
    @else
        <button type="button" wire:click="openSuspend" class="btn btn-secondary !px-3 sm:!px-[18px]" style="color:#f87171">
            <i class="fa-solid fa-ban"></i><span class="hidden sm:inline">Tangguhkan Bisnis</span>
        </button>
    @endif
</x-slot>

<div class="flex flex-col gap-5">
    <div class="flex items-center gap-2 text-[12.5px] text-pb-muted -mb-1">
        <a href="{{ route('admin.businesses.index') }}" wire:navigate class="link"><i class="fa-solid fa-arrow-left mr-1"></i>Bisnis</a>
        <span>/</span><span>{{ $business->name }}</span>
    </div>

    @if ($business->isSuspended())
        <div class="card animate-fadeUp">
            <div class="card-body flex gap-3 items-start text-[13px]">
                <i class="fa-solid fa-ban mt-0.5" style="color:#f87171"></i>
                <div>
                    <div class="font-semibold">Ditangguhkan sejak {{ $business->suspended_at->timezone($tz)->format('d/m/Y H:i') }}</div>
                    <div class="text-pb-muted">Alasan: {{ $business->suspension_reason }}. Semua anggota tidak bisa masuk ke bisnis ini maupun aplikasi kasir.</div>
                </div>
            </div>
        </div>
    @endif

    <div class="stat-grid">
        <div class="stat-card animate-fadeUp"><div class="min-w-0 flex flex-col gap-1"><div class="stat-num">{{ $this->outlets->count() }}</div><div class="stat-label">Outlet</div></div></div>
        <div class="stat-card animate-fadeUp d1"><div class="min-w-0 flex flex-col gap-1"><div class="stat-num">{{ $this->members->count() }}</div><div class="stat-label">Anggota tim</div></div></div>
        <div class="stat-card animate-fadeUp d2"><div class="min-w-0 flex flex-col gap-1"><div class="stat-num whitespace-nowrap !text-[22px]">{{ Money::format($s['revenue']) }}</div><div class="stat-label">Penjualan 30 hari · {{ $s['orders'] }} pesanan</div></div></div>
        <div class="stat-card animate-fadeUp d3"><div class="min-w-0 flex flex-col gap-1"><div class="stat-num">{{ number_format($s['orders_all'], 0, ',', '.') }}</div><div class="stat-label">Total pesanan lunas</div></div></div>
    </div>

    <div class="card overflow-hidden animate-fadeUp">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-users"></i>Anggota &amp; Owner</div>
            <div class="text-[12px] text-pb-muted">{{ collect([$business->email, $business->phone, $business->timezone])->filter()->join(' · ') }}</div>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Nama</th><th>Role</th><th>Status akun</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->members as $member)
                        @php $user = $member->user; @endphp
                        <tr wire:key="mem-{{ $member->id }}">
                            <td class="td-main">{{ $user->name }}<div class="text-[11px] text-pb-muted font-normal">{{ $user->email }}</div></td>
                            <td>
                                <span class="badge {{ $member->role->value === 'owner' ? 'badge-amber' : 'badge-gray' }}">{{ $member->role->label() }}</span>
                                @unless ($member->is_active)<span class="badge badge-gray">Keanggotaan nonaktif</span>@endunless
                            </td>
                            <td>
                                @if ($user->isDeactivated())
                                    <span class="badge badge-red" title="{{ $user->deactivation_reason }}">Nonaktif</span>
                                    <div class="text-[11px] text-pb-muted mt-0.5">{{ $user->deactivation_reason }}</div>
                                @else
                                    <span class="badge badge-green">Aktif</span>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <button type="button" wire:click="sendReset({{ $user->id }})" wire:confirm="Kirim link reset password ke {{ $user->email }}?" class="btn-ghost" title="Kirim link reset password"><i class="fa-solid fa-key"></i></button>
                                @if ($user->isDeactivated())
                                    <button type="button" wire:click="activateUser({{ $user->id }})" class="btn-ghost font-semibold">Aktifkan</button>
                                @elseif (! $user->isSuperAdmin())
                                    <button type="button" wire:click="openDeactivate({{ $user->id }})" class="btn-ghost font-semibold" style="color:#f87171">Nonaktifkan</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="two-col">
        <div class="card overflow-hidden animate-fadeUp min-w-0">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-shop"></i>Outlet</div></div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>Outlet</th><th>Status</th><th>Kasir offline</th></tr></thead>
                    <tbody>
                        @forelse ($this->outlets as $outlet)
                            <tr wire:key="out-{{ $outlet->id }}">
                                <td class="td-main">{{ $outlet->name }}<div class="text-[11px] text-pb-muted font-normal">{{ $outlet->code }} · {{ $outlet->address }}</div></td>
                                <td><span class="badge {{ $outlet->is_active ? 'badge-green' : 'badge-gray' }}">{{ $outlet->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                                <td class="text-[12.5px]">{{ $outlet->offline_enabled ? 'Aktif · '.$outlet->pos_devices_count.' perangkat' : 'Tidak' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center !py-8">Belum ada outlet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card overflow-hidden animate-fadeUp d1 min-w-0">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i>Tindakan Super Admin</div></div>
            <div class="card-body flex flex-col gap-3">
                @forelse ($this->activities as $activity)
                    <div wire:key="act-{{ $activity->id }}" class="text-[13px]">
                        <span class="badge {{ $activity->actionBadge() }}">{{ $activity->actionLabel() }}</span>
                        <div class="font-medium mt-1">{{ $activity->label }}</div>
                        <div class="text-[11.5px] text-pb-muted">{{ $activity->user?->name ?? 'Sistem' }} · {{ $activity->created_at->timezone($tz)->format('d/m/Y H:i') }}{{ isset($activity->details['alasan']) ? ' · '.$activity->details['alasan'] : '' }}</div>
                    </div>
                @empty
                    <p class="text-[12.5px] text-pb-muted">Belum ada tindakan untuk bisnis ini.</p>
                @endforelse
            </div>
        </div>
    </div>

    <x-modal name="admin-reason" maxWidth="md" focusable>
        <form wire:submit="confirm">
            <div class="px-6 py-5 border-b border-pb-border font-display text-base font-bold text-pb-text">
                {{ $targetUserId ? 'Nonaktifkan akun' : 'Tangguhkan '.$business->name }}
            </div>
            <div class="px-6 py-5 flex flex-col gap-3">
                <p class="text-[13px] text-pb-muted">
                    @if ($targetUserId)
                        Akun ini langsung keluar dari web dan aplikasi kasir, dan tidak bisa login sampai diaktifkan kembali.
                    @else
                        Semua anggota bisnis ini (owner, admin, kasir) tidak bisa memakai web maupun aplikasi kasir sampai bisnis diaktifkan kembali. Data tetap tersimpan.
                    @endif
                </p>
                <div>
                    <x-input-label for="reason" value="Alasan" />
                    <x-text-input wire:model="reason" id="reason" placeholder="mis. tagihan langganan belum dibayar" required />
                    <x-input-error :messages="$errors->get('reason')" class="mt-1.5" />
                    <x-input-error :messages="$errors->get('user')" class="mt-1.5" />
                </div>
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-danger-button wire:loading.attr="disabled" wire:target="confirm"><i class="fa-solid fa-ban"></i>{{ $targetUserId ? 'Nonaktifkan' : 'Tangguhkan' }}</x-danger-button>
            </div>
        </form>
    </x-modal>
</div>
