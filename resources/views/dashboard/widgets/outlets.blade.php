@php
    use App\Support\Money;

    $rows = $data->outletPerformance();
    $total = max(1, $rows->sum('net_sales'));
@endphp

<div class="card overflow-hidden">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-store"></i>Performa Outlet Bulan Ini</div>
        @can('reports.view')
            <a href="{{ route('reports.index', ['report' => 'profit']) }}" wire:navigate class="btn-ghost font-semibold">Laba per outlet <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
        @endcan
    </div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>Outlet</th><th class="text-right">Order</th><th class="text-right">Penjualan bersih</th><th class="text-right">Laba kotor</th><th class="w-56">Kontribusi</th></tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="td-main">{{ $row->outlet->name }}</td>
                        <td class="text-right">{{ number_format($row->orders, 0, ',', '.') }}</td>
                        <td class="text-right whitespace-nowrap">{{ Money::format($row->net_sales) }}</td>
                        <td class="text-right whitespace-nowrap">{{ Money::format($row->gross_profit) }}</td>
                        <td>
                            <div class="flex items-center gap-2">
                                <div class="prog-bar flex-1"><div class="prog-fill" style="width: {{ round($row->net_sales / $total * 100) }}%"></div></div>
                                <span class="text-[12px] text-pb-muted w-10 text-right">{{ round($row->net_sales / $total * 100) }}%</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
