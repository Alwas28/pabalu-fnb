<?php

use App\Enums\PaymentMethod;
use App\Models\ExpenseCategory;
use App\Support\Money;
use App\Support\Reports\FinanceMetrics;
use App\Support\Reports\Period;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Keuangan')] class extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** '' = semua outlet */
    #[Url(as: 'outlet')]
    public string $outletId = '';

    public function mount(): void
    {
        $period = Period::make($this->from ?: null, $this->to ?: null, $this->business()->timezone);
        $this->from = $period->from->toDateString();
        $this->to = $period->to->toDateString();
    }

    private function business()
    {
        return auth()->user()->activeBusiness();
    }

    #[Computed]
    public function outlets()
    {
        return auth()->user()->accessibleOutlets();
    }

    #[Computed]
    public function metrics(): FinanceMetrics
    {
        $ids = $this->outlets->pluck('id');
        $selected = $ids->contains((int) $this->outletId) ? [(int) $this->outletId] : $ids->all();

        return new FinanceMetrics($this->business()->id, $selected, Period::make($this->from, $this->to, $this->business()->timezone), $this->outletId === '');
    }

    /** Total pengeluaran per nama kategori, terbesar dulu. */
    #[Computed]
    public function expenseByCategory()
    {
        $totals = $this->metrics->expenses()->selectRaw('expense_category_id, sum(amount) as total')->groupBy('expense_category_id')
            ->pluck('total', 'expense_category_id');
        $names = ExpenseCategory::whereIn('id', $totals->keys())->pluck('name', 'id');

        return $totals->mapWithKeys(fn ($total, $id) => [$names[$id] ?? '—' => (int) $total])->sortDesc();
    }
}; ?>

<x-slot name="actions">
    <a href="{{ route('expenses.index') }}" wire:navigate class="btn btn-primary !px-3 sm:!px-[18px]"><i class="fa-solid fa-money-bill-wave"></i><span class="hidden sm:inline">Pengeluaran</span></a>
</x-slot>

<div class="flex flex-col gap-5">
    @php
        $s = $this->metrics->summary();
        $cash = $this->metrics->cashFlow();
        $methods = $this->metrics->paymentsByMethod();
    @endphp

    <div class="flex flex-wrap items-end gap-2">
        <div><x-input-label for="from" value="Dari" /><input type="date" wire:model.live="from" id="from" class="f-input !py-1.5 !w-40 !text-[12.5px]"></div>
        <div><x-input-label for="to" value="Sampai" /><input type="date" wire:model.live="to" id="to" class="f-input !py-1.5 !w-40 !text-[12.5px]"></div>
        <div>
            <x-input-label for="outletId" value="Outlet" />
            <select wire:model.live="outletId" id="outletId" class="f-input !py-1.5 !w-48 !text-[12.5px]">
                <option value="">Semua outlet</option>
                @foreach ($this->outlets as $outlet)
                    <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
                @endforeach
            </select>
        </div>
        <a href="{{ route('finance.export', array_filter(['from' => $from, 'to' => $to, 'outlet' => $outletId])) }}"
           class="btn btn-secondary !py-1.5 sm:ml-auto" title="Laba rugi, arus kas, penjualan harian, metode bayar, pengeluaran & daftar pesanan">
            <i class="fa-solid fa-file-excel" style="color:#16a34a"></i>Unduh Excel
        </a>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Penjualan bersih</div><div class="stat-num order-first whitespace-nowrap !text-[22px]">{{ Money::format($s['net_sales']) }}</div><div class="text-[11.5px] text-pb-muted">{{ number_format($s['orders'], 0, ',', '.') }} transaksi</div></div></div>
        <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Laba kotor</div><div class="stat-num order-first whitespace-nowrap !text-[22px]">{{ Money::format($s['gross_profit']) }}</div><div class="text-[11.5px] text-pb-muted">margin {{ number_format($s['gross_margin'], 1, ',', '.') }}%</div></div></div>
        <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Pengeluaran operasional</div><div class="stat-num order-first whitespace-nowrap !text-[22px] trend-down">{{ Money::format($s['expenses']) }}</div></div></div>
        <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Laba operasional</div><div @class(['stat-num', 'trend-up' => $s['operating_profit'] > 0, 'trend-down' => $s['operating_profit'] < 0])>{{ Money::format($s['operating_profit']) }}</div></div></div>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="card animate-fadeUp">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-scale-balanced"></i>Laba Rugi</div></div>
            <div class="card-body flex flex-col gap-1.5 text-[13px]">
                <div class="flex justify-between text-pb-sub"><span>Penjualan kotor</span><span>{{ Money::format($s['gross_sales']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>Diskon</span><span>-{{ Money::format($s['discount']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>Refund (porsi penjualan)</span><span>-{{ Money::format($s['gross_sales'] - $s['discount'] - $s['net_sales']) }}</span></div>
                <div class="flex justify-between font-bold text-pb-text border-t border-pb-border pt-1.5"><span>Penjualan bersih</span><span>{{ Money::format($s['net_sales']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>HPP bahan (resep)</span><span>-{{ Money::format($s['cogs']) }}</span></div>
                <div class="flex justify-between font-bold text-pb-text border-t border-pb-border pt-1.5"><span>Laba kotor</span><span>{{ Money::format($s['gross_profit']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>Pengeluaran operasional</span><span>-{{ Money::format($s['expenses']) }}</span></div>
                <div class="flex justify-between font-display text-base font-bold text-pb-text border-t border-pb-border pt-1.5"><span>Laba operasional</span><span>{{ Money::format($s['operating_profit']) }}</span></div>
                <div class="text-[11.5px] text-pb-muted mt-2">Pajak {{ Money::format($s['tax']) }} &amp; service charge {{ Money::format($s['service_charge']) }} dicatat terpisah. Void: {{ $s['void_count'] }} transaksi ({{ Money::format($s['void_amount']) }}).</div>
            </div>
        </div>

        <div class="card animate-fadeUp d1">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-money-bill-transfer"></i>Arus Kas</div></div>
            <div class="card-body flex flex-col gap-1.5 text-[13px]">
                <div class="flex justify-between text-pb-sub"><span>Diterima dari pelanggan</span><span class="trend-up">{{ Money::format($cash['received']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>Refund ke pelanggan</span><span>-{{ Money::format($cash['refunded']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>Pembayaran supplier</span><span>-{{ Money::format($cash['supplier_payments']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>Belanja cepat (tanpa PO)</span><span>-{{ Money::format($cash['quick_purchases']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>Pengeluaran operasional</span><span>-{{ Money::format($cash['expenses']) }}</span></div>
                <div @class(['flex justify-between font-display text-base font-bold border-t border-pb-border pt-1.5', 'text-pb-text' => $cash['net'] >= 0, 'trend-down' => $cash['net'] < 0])><span>Arus kas bersih</span><span>{{ Money::format($cash['net']) }}</span></div>
                <div class="text-[11.5px] text-pb-muted mt-2">Berdasarkan tanggal uang diterima / dibayar, bukan tanggal transaksi.</div>
            </div>
        </div>

        <div class="card animate-fadeUp d2">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-wallet"></i>Metode Pembayaran</div></div>
            <div class="card-body flex flex-col gap-2 text-[13px]">
                @php $methodTotal = max(1, collect($methods)->sum('net')); @endphp
                @forelse ($methods as $method => $row)
                    <div>
                        <div class="flex justify-between"><span class="text-pb-text">{{ PaymentMethod::tryFrom($method)?->label() ?? $method }}</span><span class="font-semibold">{{ Money::format($row['net']) }}</span></div>
                        <div class="prog-bar mt-1"><div class="prog-fill" style="width: {{ max(2, round($row['net'] / $methodTotal * 100)) }}%"></div></div>
                        @if ($row['refunded'])<div class="text-[11px] text-pb-muted mt-0.5">refund {{ Money::format($row['refunded']) }}</div>@endif
                    </div>
                @empty
                    <div class="text-pb-muted">Belum ada pembayaran.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="card animate-fadeUp">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-chart-pie"></i>Pengeluaran per Kategori</div>
            <a href="{{ route('expenses.index', array_filter(['from' => $from, 'to' => $to, 'outlet' => $outletId])) }}" wire:navigate class="btn-ghost font-semibold">Kelola Pengeluaran <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
        </div>
        <div class="card-body grid gap-x-8 gap-y-2 text-[13px] sm:grid-cols-2">
            @forelse ($this->expenseByCategory as $name => $total)
                <div class="flex justify-between"><span class="text-pb-sub">{{ $name }}</span><span class="font-semibold">{{ Money::format($total) }}</span></div>
            @empty
                <div class="text-pb-muted">Belum ada pengeluaran pada periode ini.</div>
            @endforelse
        </div>
    </div>
</div>
