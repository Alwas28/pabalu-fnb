<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\AdminActivity;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Support\Analytics\Outbox;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Ringkasan Platform')] class extends Component
{
    /** @return array<string, int> */
    #[Computed]
    public function stats(): array
    {
        $since = now()->subDays(30);
        $sales = Order::query()
            ->where('status', '!=', OrderStatus::Cancelled)
            ->where('payment_status', '!=', PaymentStatus::Pending)
            ->where('placed_at', '>=', $since)
            ->selectRaw('count(*) as orders, coalesce(sum(total - refunded_amount), 0) as revenue')
            ->first();

        return [
            'businesses' => Business::count(),
            'suspended' => Business::whereNotNull('suspended_at')->count(),
            'new_businesses' => Business::where('created_at', '>=', $since)->count(),
            'owners' => BusinessUser::where('role', UserRole::Owner)->distinct()->count('user_id'),
            'users' => User::where('is_super_admin', false)->count(),
            'deactivated' => User::whereNotNull('deactivated_at')->count(),
            'outlets' => Outlet::where('is_active', true)->count(),
            'orders' => (int) $sales->orders,
            'revenue' => (int) $sales->revenue,
        ];
    }

    #[Computed]
    public function latestBusinesses()
    {
        return Business::with('owner')->withCount('outlets')->latest()->limit(6)->get();
    }

    #[Computed]
    public function latestActivities()
    {
        return AdminActivity::with('user')->latest('created_at')->latest('id')->limit(6)->get();
    }

    /** @return array{enabled: bool, pending: int, retrying: int, last_sent: mixed, last_error: ?string} */
    #[Computed]
    public function outbox(): array
    {
        $pending = OutboxEvent::whereNull('dispatched_at');

        return [
            'enabled' => Outbox::enabled(),
            'pending' => (clone $pending)->count(),
            'retrying' => (clone $pending)->where('attempts', '>', 0)->count(),
            'last_sent' => OutboxEvent::max('dispatched_at'),
            'last_error' => (clone $pending)->whereNotNull('last_error')->latest('id')->value('last_error'),
        ];
    }
}; ?>

@php $tz = config('app.admin_timezone'); $s = $this->stats; @endphp

<div class="flex flex-col gap-5">
    <div class="stat-grid">
        <div class="stat-card animate-fadeUp">
            <div class="min-w-0 flex flex-col gap-1">
                <div class="stat-num">{{ number_format($s['businesses'], 0, ',', '.') }}</div>
                <div class="stat-label">Bisnis terdaftar</div>
                <div class="text-[11.5px] text-pb-muted">{{ $s['new_businesses'] }} baru (30 hari) · {{ $s['suspended'] }} ditangguhkan</div>
            </div>
        </div>
        <div class="stat-card animate-fadeUp d1">
            <div class="min-w-0 flex flex-col gap-1">
                <div class="stat-num">{{ number_format($s['owners'], 0, ',', '.') }}</div>
                <div class="stat-label">Owner</div>
                <div class="text-[11.5px] text-pb-muted">{{ number_format($s['users'], 0, ',', '.') }} pengguna · {{ $s['deactivated'] }} nonaktif</div>
            </div>
        </div>
        <div class="stat-card animate-fadeUp d2">
            <div class="min-w-0 flex flex-col gap-1">
                <div class="stat-num">{{ number_format($s['outlets'], 0, ',', '.') }}</div>
                <div class="stat-label">Outlet aktif</div>
            </div>
        </div>
        <div class="stat-card animate-fadeUp d3">
            <div class="min-w-0 flex flex-col gap-1">
                <div class="stat-num whitespace-nowrap !text-[22px]">{{ Money::format($s['revenue']) }}</div>
                <div class="stat-label">Transaksi 30 hari (semua bisnis)</div>
                <div class="text-[11.5px] text-pb-muted">{{ number_format($s['orders'], 0, ',', '.') }} pesanan lunas</div>
            </div>
        </div>
    </div>

    <div class="two-col">
        <div class="card overflow-hidden animate-fadeUp min-w-0">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-store"></i>Bisnis Terbaru</div>
                <a href="{{ route('admin.businesses.index') }}" wire:navigate class="link text-[12.5px]">Semua bisnis</a>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>Bisnis</th><th>Owner</th><th class="text-right">Outlet</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($this->latestBusinesses as $business)
                            <tr wire:key="lb-{{ $business->id }}">
                                <td class="td-main">
                                    <a href="{{ route('admin.businesses.show', $business) }}" wire:navigate class="link">{{ $business->name }}</a>
                                    <div class="text-[11px] text-pb-muted font-normal">Daftar {{ $business->created_at->timezone($tz)->format('d/m/Y') }}</div>
                                </td>
                                <td class="text-[12.5px]">{{ $business->owner?->name ?? '—' }}<div class="text-pb-muted">{{ $business->owner?->email }}</div></td>
                                <td class="text-right">{{ $business->outlets_count }}</td>
                                <td><span class="badge {{ $business->isSuspended() ? 'badge-red' : 'badge-green' }}">{{ $business->isSuspended() ? 'Ditangguhkan' : 'Aktif' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center !py-8">Belum ada bisnis terdaftar.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card overflow-hidden animate-fadeUp d1 min-w-0">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i>Tindakan Terakhir</div>
                <a href="{{ route('admin.activity') }}" wire:navigate class="link text-[12.5px]">Semua</a>
            </div>
            <div class="card-body flex flex-col gap-3">
                @forelse ($this->latestActivities as $activity)
                    <div wire:key="la-{{ $activity->id }}" class="text-[13px]">
                        <span class="badge {{ $activity->actionBadge() }}">{{ $activity->actionLabel() }}</span>
                        <div class="font-medium mt-1">{{ $activity->label }}</div>
                        <div class="text-[11.5px] text-pb-muted">{{ $activity->user?->name ?? 'Sistem' }} · {{ $activity->created_at->timezone($tz)->format('d/m/Y H:i') }}</div>
                    </div>
                @empty
                    <p class="text-[12.5px] text-pb-muted">Belum ada tindakan super admin.</p>
                @endforelse
            </div>
        </div>
    </div>

    @php $o = $this->outbox; @endphp
    <div class="card animate-fadeUp">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-satellite-dish"></i>Pengiriman ke Analytics Platform</div>
            <span class="badge {{ ! $o['enabled'] ? 'badge-gray' : ($o['retrying'] ? 'badge-red' : 'badge-green') }}">{{ ! $o['enabled'] ? 'Tidak aktif' : ($o['retrying'] ? 'Ada kegagalan' : 'Berjalan') }}</span>
        </div>
        <div class="card-body text-[13px] flex flex-col gap-1.5">
            @if (! $o['enabled'])
                <p class="text-pb-muted">Atur <code>ANALYTICS_ENDPOINT</code> (serta <code>ANALYTICS_TOKEN</code> / <code>ANALYTICS_SIGNING_SECRET</code>) di <code>.env</code> untuk mulai mengirim event penjualan &amp; keuangan.</p>
            @else
                <div class="flex justify-between"><span class="text-pb-sub">Menunggu dikirim</span><span class="font-semibold">{{ number_format($o['pending'], 0, ',', '.') }} event</span></div>
                <div class="flex justify-between"><span class="text-pb-sub">Sedang dicoba ulang</span><span @class(['font-semibold', 'trend-down' => $o['retrying'] > 0])>{{ number_format($o['retrying'], 0, ',', '.') }} event</span></div>
                <div class="flex justify-between"><span class="text-pb-sub">Terakhir terkirim</span><span>{{ $o['last_sent'] ? \Illuminate\Support\Carbon::parse($o['last_sent'])->timezone($tz)->format('d/m/Y H:i') : '—' }}</span></div>
                @if ($o['last_error'])
                    <div class="text-[11.5px] trend-down break-all">Galat terakhir: {{ $o['last_error'] }}</div>
                @endif
            @endif
        </div>
    </div>
</div>
