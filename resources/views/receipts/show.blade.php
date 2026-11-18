@php
    use App\Enums\DiscountType;
    use App\Support\Money;

    $business = $order->business;
    $outlet = $order->outlet;
    $time = $order->placed_at->timezone($business->timezone ?: config('app.timezone'));
    $rate = fn ($r) => rtrim(rtrim(number_format((float) $r, 2, ',', ''), '0'), ',').'%';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Struk {{ $order->number }} · {{ $business->name }}</title>
    <style>
        :root { --paper: {{ $paperWidth }}mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef0f3; color: #111; font: 13px/1.45 ui-monospace, "SFMono-Regular", Menlo, Consolas, "Courier New", monospace; }
        .wrap { padding: 24px 16px 40px; display: flex; flex-direction: column; align-items: center; gap: 14px; }
        .receipt { width: 100%; max-width: 380px; background: #fff; padding: 20px 18px; border-radius: 6px; box-shadow: 0 2px 14px rgba(0,0,0,.08); }
        .center { text-align: center; }
        .brand { font-size: 17px; font-weight: 700; margin-bottom: 2px; }
        .muted { color: #555; }
        .small { font-size: 11.5px; }
        hr { border: 0; border-top: 1px dashed #999; margin: 10px 0; }
        .row { display: flex; justify-content: space-between; gap: 10px; }
        .row > span:last-child { text-align: right; white-space: nowrap; }
        .item { margin-bottom: 6px; }
        .sub { padding-left: 12px; color: #444; font-size: 12px; }
        .total { font-weight: 700; font-size: 15px; }
        .qr { width: 132px; margin: 8px auto 2px; }
        .qr svg { width: 100%; height: auto; display: block; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 700; background: #dcfce7; color: #166534; }
        .actions { display: flex; gap: 8px; }
        .actions button { font: 600 13px system-ui, sans-serif; padding: 9px 16px; border-radius: 10px; border: 1px solid #ccd; background: #fff; cursor: pointer; }
        @media print {
            @page { size: var(--paper) auto; margin: 0; }
            body { background: #fff; font-size: {{ $paperWidth >= 80 ? '12px' : '10.5px' }}; }
            .wrap { padding: 0; }
            .receipt { max-width: none; width: var(--paper); padding: 3mm 3mm 6mm; box-shadow: none; border-radius: 0; }
            .no-print { display: none !important; }
            .qr { width: {{ $paperWidth >= 80 ? '34mm' : '28mm' }}; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="receipt">
        <div class="center">
            <div class="brand">{{ $business->name }}</div>
            <div>{{ $outlet->name }}</div>
            @if ($outlet->address)<div class="muted small">{{ $outlet->address }}</div>@endif
            @if ($outlet->phone)<div class="muted small">Telp. {{ $outlet->phone }}</div>@endif
        </div>
        @if ($order->payment_status !== App\Enums\PaymentStatus::Paid)
            <div class="center" style="margin-top:8px;font-weight:700">{{ $order->status === App\Enums\OrderStatus::Cancelled ? '*** DIBATALKAN ***' : '*** TAGIHAN — BELUM LUNAS ***' }}</div>
        @endif
        <hr>
        <div class="row"><span>No</span><span>{{ $order->number }}</span></div>
        <div class="row"><span>Waktu</span><span>{{ $time->format('d/m/Y H:i') }}</span></div>
        <div class="row"><span>Kasir</span><span>{{ $order->cashier?->name ?? '-' }}</span></div>
        <div class="row"><span>Layanan</span><span>{{ $order->service_type->label() }}{{ $order->table_number ? ' · Meja '.$order->table_number : '' }}</span></div>
        @if ($order->customer_name)
            <div class="row"><span>Pelanggan</span><span>{{ $order->customer_name }}</span></div>
        @endif
        @if ($order->delivery_channel)
            <div class="row"><span>Kanal</span><span>{{ $order->delivery_channel->label() }}{{ $order->delivery_reference ? ' #'.$order->delivery_reference : '' }}</span></div>
        @endif
        <hr>
        @foreach ($order->items->where('quantity', '>', 0) as $item)
            <div class="item">
                <div>{{ $item->displayName() }}</div>
                <div class="row sub"><span>{{ $item->quantity }} × {{ Money::plain($item->unit_price) }}</span><span>{{ Money::plain($item->line_total) }}</span></div>
                @foreach ($item->modifiers as $modifier)
                    <div class="sub">+ {{ $modifier->name }}</div>
                @endforeach
                @if ($item->notes)
                    <div class="sub">* {{ $item->notes }}</div>
                @endif
            </div>
        @endforeach
        <hr>
        <div class="row"><span>Subtotal</span><span>{{ Money::plain($order->subtotal) }}</span></div>
        @if ($order->discount_amount > 0)
            <div class="row"><span>Diskon{{ $order->discount_type === DiscountType::Percent ? ' '.$rate($order->discount_value) : '' }}</span><span>-{{ Money::plain($order->discount_amount) }}</span></div>
        @endif
        @if ($order->service_charge_amount > 0)
            <div class="row"><span>Service {{ $rate($order->service_charge_rate) }}</span><span>{{ Money::plain($order->service_charge_amount) }}</span></div>
        @endif
        @if ($order->tax_amount > 0 && ! $order->prices_include_tax)
            <div class="row"><span>Pajak {{ $rate($order->tax_rate) }}</span><span>{{ Money::plain($order->tax_amount) }}</span></div>
        @endif
        <div class="row total" style="margin-top:4px"><span>TOTAL</span><span>{{ Money::format($order->total) }}</span></div>
        @if ($order->tax_amount > 0 && $order->prices_include_tax)
            <div class="row muted small"><span>Termasuk pajak {{ $rate($order->tax_rate) }}</span><span>{{ Money::plain($order->tax_amount) }}</span></div>
        @endif
        <hr>
        @foreach ($order->payments as $payment)
            <div class="row"><span>{{ $payment->method->label() }}</span><span>{{ Money::plain($payment->tendered_amount) }}</span></div>
            @if ($payment->reference)
                <div class="sub">Ref: {{ $payment->reference }}</div>
            @endif
        @endforeach
        @if ($order->change_amount > 0)
            <div class="row" style="font-weight:700"><span>Kembalian</span><span>{{ Money::plain($order->change_amount) }}</span></div>
        @endif
        <div class="center" style="margin-top:8px"><span class="badge" @if ($order->payment_status !== App\Enums\PaymentStatus::Paid) style="background:#fef3c7;color:#92400e" @endif>{{ $order->status === App\Enums\OrderStatus::Cancelled ? 'Dibatalkan' : $order->payment_status->label() }}</span></div>

        <div class="qr">{!! $qr !!}</div>
        <div class="center small muted">Scan untuk struk digital</div>
        <div class="center" style="margin-top:10px">{{ $outlet->receipt_footer ?: 'Terima kasih atas kunjungan Anda' }}</div>
    </div>

    <div class="actions no-print">
        <button type="button" onclick="window.print()">🖨 Cetak</button>
    </div>
</div>
@if ($autoPrint)
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 250));</script>
@endif
</body>
</html>
