@php
    $time = now($order->outlet->business->timezone ?: config('app.timezone'));
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Tiket Dapur {{ $order->number }}</title>
    <style>
        :root { --paper: {{ $paperWidth }}mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef0f3; color: #000; font: 14px/1.4 ui-monospace, Menlo, Consolas, "Courier New", monospace; }
        .wrap { padding: 24px 16px; display: flex; flex-direction: column; align-items: center; gap: 14px; }
        .ticket { width: 100%; max-width: 360px; background: #fff; padding: 16px; border-radius: 6px; }
        .center { text-align: center; }
        .big { font-size: 22px; font-weight: 800; }
        hr { border: 0; border-top: 1px dashed #000; margin: 8px 0; }
        .row { display: flex; justify-content: space-between; }
        .item { font-weight: 700; font-size: 15px; margin-top: 6px; }
        .sub { padding-left: 22px; font-size: 13px; }
        .note { padding-left: 22px; font-size: 13px; font-weight: 700; }
        .no-print button { font: 600 13px system-ui, sans-serif; padding: 9px 16px; border-radius: 10px; border: 1px solid #ccd; background: #fff; cursor: pointer; }
        @media print {
            @page { size: var(--paper) auto; margin: 0; }
            body { background: #fff; }
            .wrap { padding: 0; }
            .ticket { width: var(--paper); max-width: none; padding: 3mm 3mm 8mm; border-radius: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="ticket">
        <div class="center" style="font-weight:700">TIKET DAPUR</div>
        @if ($batch > 1)
            <div class="center" style="font-weight:700">*** TAMBAHAN #{{ $batch - 1 }} ***</div>
        @endif
        <div class="center big">{{ $order->table_number ? 'MEJA '.$order->table_number : strtoupper($order->service_type->label()) }}</div>
        <hr>
        <div class="row"><span>No</span><span>{{ $order->number }}</span></div>
        <div class="row"><span>Jam</span><span>{{ $time->format('H:i') }}</span></div>
        @if ($order->customer_name)
            <div class="row"><span>Nama</span><span>{{ $order->customer_name }}</span></div>
        @endif
        <hr>
        @foreach ($items as $item)
            <div class="item">{{ $item->quantity }}× {{ $item->displayName() }}</div>
            @foreach ($item->modifiers as $modifier)
                <div class="sub">+ {{ $modifier->name }}</div>
            @endforeach
            @if ($item->notes)
                <div class="note">! {{ $item->notes }}</div>
            @endif
        @endforeach
        @if ($order->notes && $batch === 1)
            <hr><div>Catatan: {{ $order->notes }}</div>
        @endif
    </div>
    <div class="no-print"><button type="button" onclick="window.print()">🖨 Cetak</button></div>
</div>
@if ($autoPrint)
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 250));</script>
@endif
</body>
</html>
