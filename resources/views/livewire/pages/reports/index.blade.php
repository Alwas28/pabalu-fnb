<?php

use App\Support\Money;
use App\Support\Reports\Period;
use App\Support\Reports\ReportBuilder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Laporan')] class extends Component
{
    #[Url]
    public string $report = 'daily';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url(as: 'outlet')]
    public string $outletId = '';

    public function mount(): void
    {
        $period = $this->period();
        $this->from = $period->from->toDateString();
        $this->to = $period->to->toDateString();

        if (! array_key_exists($this->report, ReportBuilder::REPORTS)) {
            $this->report = 'daily';
        }
    }

    private function period(): Period
    {
        return Period::make($this->from ?: null, $this->to ?: null, auth()->user()->activeBusiness()->timezone);
    }

    #[Computed]
    public function outlets()
    {
        return auth()->user()->accessibleOutlets();
    }

    /** Hanya outlet yang boleh diakses; filter outlet dari URL yang tidak berhak diabaikan. */
    private function outletIds(): array
    {
        $ids = $this->outlets->pluck('id');

        return $ids->contains((int) $this->outletId) ? [(int) $this->outletId] : $ids->all();
    }

    #[Computed]
    public function table(): array
    {
        $report = array_key_exists($this->report, ReportBuilder::REPORTS) ? $this->report : 'daily';

        return (new ReportBuilder(auth()->user()->activeBusiness()->id, $this->outletIds(), $this->period()))->build($report);
    }

    public function export(): StreamedResponse
    {
        $table = $this->table;
        $name = 'laporan-'.$this->report.'-'.$this->from.'_'.$this->to.'.csv';

        return response()->streamDownload(function () use ($table) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
            fputcsv($out, array_column($table['columns'], 0), ';');
            foreach ($table['rows'] as $row) {
                fputcsv($out, $row, ';');
            }
            if ($table['totals']) {
                fputcsv($out, $table['totals'], ';');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function format(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return $type === 'text' ? '' : '—';
        }

        return match ($type) {
            'money' => Money::format((int) $value),
            'percent' => number_format((float) $value, 1, ',', '.').'%',
            'number' => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ','),
            default => (string) $value,
        };
    }
}; ?>

<x-slot name="actions">
    <button type="button" wire:click="export" class="btn btn-secondary !px-3 sm:!px-[18px]"><i class="fa-solid fa-file-csv"></i><span class="hidden sm:inline">Ekspor CSV</span></button>
</x-slot>

<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end gap-2">
        <div>
            <x-input-label for="report" value="Laporan" />
            <select wire:model.live="report" id="report" class="f-input !py-1.5 !w-56 !text-[12.5px]">
                <optgroup label="Penjualan">
                    @foreach (['daily', 'outlet', 'cashier', 'service'] as $key)<option value="{{ $key }}">{{ ReportBuilder::REPORTS[$key] }}</option>@endforeach
                </optgroup>
                <optgroup label="Menu">
                    @foreach (['menu', 'category', 'unsold'] as $key)<option value="{{ $key }}">{{ ReportBuilder::REPORTS[$key] }}</option>@endforeach
                </optgroup>
                <optgroup label="Pembayaran & penyesuaian">
                    @foreach (['payment', 'adjustments'] as $key)<option value="{{ $key }}">{{ ReportBuilder::REPORTS[$key] }}</option>@endforeach
                </optgroup>
                <optgroup label="Persediaan & pembelian">
                    @foreach (['inventory', 'waste', 'purchasing'] as $key)<option value="{{ $key }}">{{ ReportBuilder::REPORTS[$key] }}</option>@endforeach
                </optgroup>
                <optgroup label="Laba">
                    <option value="profit">{{ ReportBuilder::REPORTS['profit'] }}</option>
                </optgroup>
            </select>
        </div>
        <div><x-input-label for="from" value="Dari" /><input type="date" wire:model.live="from" id="from" class="f-input !py-1.5 !w-40 !text-[12.5px]"></div>
        <div><x-input-label for="to" value="Sampai" /><input type="date" wire:model.live="to" id="to" class="f-input !py-1.5 !w-40 !text-[12.5px]"></div>
        @if ($this->outlets->count() > 1)
            <div>
                <x-input-label for="outletId" value="Outlet" />
                <select wire:model.live="outletId" id="outletId" class="f-input !py-1.5 !w-48 !text-[12.5px]">
                    <option value="">Semua outlet</option>
                    @foreach ($this->outlets as $outlet)
                        <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    @php $table = $this->table; @endphp
    <div class="card overflow-hidden animate-fadeUp">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fa-solid fa-chart-line"></i>{{ ReportBuilder::REPORTS[$report] ?? '' }}</div>
                <div class="section-desc">
                    {{ in_array($report, ['inventory'], true) ? 'Posisi stok saat ini' : \Carbon\Carbon::parse($from)->translatedFormat('d M Y').' – '.\Carbon\Carbon::parse($to)->translatedFormat('d M Y') }}
                    · {{ count($table['rows']) }} baris
                </div>
            </div>
            <div wire:loading class="text-[12px] text-pb-muted"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Memuat…</div>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        @foreach ($table['columns'] as [$label, $type])
                            <th @class(['text-right' => $type !== 'text'])>{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($table['rows'] as $i => $row)
                        <tr wire:key="r-{{ $report }}-{{ $i }}">
                            @foreach ($table['columns'] as $c => [$label, $type])
                                <td @class(['text-right whitespace-nowrap' => $type !== 'text', 'td-main' => $c === 0 || ($c === 1 && $table['columns'][0][1] === 'number'), '!whitespace-normal' => $type === 'text' && $c > 0])>
                                    {{ $this->format($row[$c] ?? null, $type) }}
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($table['columns']) }}" class="text-center !py-8">Tidak ada data pada periode ini.</td></tr>
                    @endforelse
                </tbody>
                @if ($table['totals'])
                    <tfoot>
                        <tr class="font-bold text-pb-text border-t-2 border-pb-border">
                            @foreach ($table['columns'] as $c => [$label, $type])
                                <td @class(['px-[14px] py-3', 'text-right whitespace-nowrap' => $type !== 'text'])>
                                    {{ is_string($table['totals'][$c] ?? null) ? $table['totals'][$c] : $this->format($table['totals'][$c] ?? null, $type === 'text' ? 'text' : $type) }}
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <div class="px-5 py-3 border-t border-pb-border text-[11.5px] text-pb-muted">
            Penjualan bersih = subtotal − diskon − porsi refund (tanpa pajak & service). Transaksi void & pesanan belum dibayar tidak dihitung. Biaya bahan = estimasi dari resep saat transaksi.
        </div>
    </div>
</div>
