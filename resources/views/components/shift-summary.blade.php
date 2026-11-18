@props(['summary', 'shift' => null])

@php
    use App\Enums\PaymentMethod;
    use App\Support\Money;
@endphp

{{-- Rekap shift: kas laci & pembayaran per metode. --}}
<div {{ $attributes->merge(['class' => 'grid gap-4 md:grid-cols-2']) }}>
    <div class="rounded-xl border border-pb-border p-4 flex flex-col gap-1.5 text-[13px]">
        <div class="section-title mb-1"><i class="fa-solid fa-cash-register a-text mr-1.5"></i>Kas di laci</div>
        <div class="flex justify-between text-pb-sub"><span>Modal awal</span><span>{{ Money::format($summary['opening_cash']) }}</span></div>
        <div class="flex justify-between text-pb-sub"><span>Penjualan tunai</span><span>{{ Money::format($summary['cash_sales']) }}</span></div>
        <div class="flex justify-between text-pb-sub"><span>Kas masuk</span><span>{{ Money::format($summary['cash_in']) }}</span></div>
        <div class="flex justify-between text-pb-sub"><span>Kas keluar</span><span>-{{ Money::format($summary['cash_out']) }}</span></div>
        <div class="flex justify-between font-bold text-pb-text border-t border-pb-border pt-1.5 mt-1"><span>Kas ekspektasi</span><span>{{ Money::format($summary['expected_cash']) }}</span></div>
        @if ($shift && ! $shift->isOpen())
            <div class="flex justify-between text-pb-sub"><span>Kas aktual</span><span>{{ Money::format($shift->actual_cash) }}</span></div>
            <div @class(['flex justify-between font-bold', 'trend-up' => $shift->cash_difference > 0, 'trend-down' => $shift->cash_difference < 0, 'text-pb-text' => $shift->cash_difference === 0])>
                <span>Selisih</span><span>{{ $shift->cash_difference > 0 ? '+' : '' }}{{ Money::format($shift->cash_difference) }}</span>
            </div>
        @endif
    </div>

    <div class="rounded-xl border border-pb-border p-4 flex flex-col gap-1.5 text-[13px]">
        <div class="section-title mb-1"><i class="fa-solid fa-wallet a-text mr-1.5"></i>Penerimaan</div>
        @forelse ($summary['payments_by_method'] as $method => $amount)
            <div class="flex justify-between text-pb-sub"><span>{{ PaymentMethod::tryFrom($method)?->label() ?? $method }}</span><span>{{ Money::format($amount) }}</span></div>
        @empty
            <div class="text-pb-muted">Belum ada pembayaran.</div>
        @endforelse
        <div class="flex justify-between font-bold text-pb-text border-t border-pb-border pt-1.5 mt-1"><span>Total diterima</span><span>{{ Money::format($summary['payments_total']) }}</span></div>
        <div class="flex justify-between text-pb-sub"><span>Order dibayar</span><span>{{ $summary['orders_paid'] }}</span></div>
        @if ($summary['discount_total'] > 0)
            <div class="flex justify-between text-pb-sub"><span>Total diskon</span><span>{{ Money::format($summary['discount_total']) }}</span></div>
        @endif
        @if (($shift?->isOpen() ?? true) && $summary['open_bills'] > 0)
            <div class="text-[12px] badge badge-amber self-start mt-1"><i class="fa-solid fa-triangle-exclamation"></i>{{ $summary['open_bills'] }} pesanan belum dibayar di outlet</div>
        @endif
    </div>
</div>
