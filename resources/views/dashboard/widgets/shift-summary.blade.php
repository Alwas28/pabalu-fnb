@php
    use App\Support\Money;

    $shift = $data->kasirShift();
    $summary = $data->shiftSummary();
@endphp

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-cash-register"></i>Ringkasan Shift</div>
        @if ($shift)
            <span class="badge badge-green">Sejak {{ $shift->opened_at->timezone($data->timezone)->format('H:i') }}</span>
        @endif
    </div>
    <div class="card-body flex flex-col gap-1.5 text-[13px]">
        @if ($summary)
            <div class="flex justify-between text-pb-sub"><span>Modal awal</span><span>{{ Money::format($summary['opening_cash']) }}</span></div>
            @foreach ($summary['payments_by_method'] as $method => $amount)
                <div class="flex justify-between text-pb-sub"><span>{{ \App\Enums\PaymentMethod::tryFrom($method)?->label() ?? $method }}</span><span>{{ Money::format($amount) }}</span></div>
            @endforeach
            <div class="flex justify-between text-pb-sub"><span>Kas masuk / keluar</span><span>{{ Money::format($summary['cash_in'] - $summary['cash_out']) }}</span></div>
            <div class="flex justify-between font-bold text-pb-text border-t border-pb-border pt-1.5 mt-1"><span>Kas ekspektasi</span><span>{{ Money::format($summary['expected_cash']) }}</span></div>
            <a href="{{ route('shifts.index') }}" wire:navigate class="btn btn-secondary w-full mt-3"><i class="fa-solid fa-lock"></i>Kelola / Tutup Shift</a>
        @else
            <p class="text-pb-muted">Shift belum dibuka. Buka shift dengan modal awal kas sebelum bertransaksi.</p>
            <a href="{{ route('pos') }}" wire:navigate class="btn btn-primary w-full mt-3"><i class="fa-solid fa-door-open"></i>Buka Shift</a>
        @endif
    </div>
</div>
