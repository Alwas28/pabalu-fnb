<?php

namespace App\Support\Reports\Excel;

use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\Order;
use App\Support\Reports\FinanceMetrics;
use App\Support\Reports\Period;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan keuangan Excel: ringkasan laba rugi & arus kas, penjualan harian, metode bayar,
 * pengeluaran, dan daftar pesanan. Angka mengikuti FinanceMetrics (sama dengan halaman Keuangan).
 */
class FinanceWorkbook
{
    /**
     * @param  list<int>  $outletIds
     * @param  bool  $includeShared  sertakan biaya bersama (tanpa outlet), yaitu saat "Semua outlet"
     */
    public function __construct(
        private string $businessName,
        private int $businessId,
        private array $outletIds,
        private string $outletLabel,
        private Period $period,
        private bool $includeShared,
    ) {}

    public function build(): WorkbookWriter
    {
        $metrics = new FinanceMetrics($this->businessId, $this->outletIds, $this->period, $this->includeShared);
        $excel = new WorkbookWriter('Laporan Keuangan', $this->businessName);
        $info = [
            $this->businessName.' · '.$this->outletLabel,
            'Periode: '.$this->period->label(),
            'Dibuat: '.now($this->period->timezone)->translatedFormat('d M Y H:i'),
        ];

        $this->summary($excel, $metrics, $info);
        $this->daily($excel, $metrics, $info);
        $this->payments($excel, $metrics, $info);
        $this->expenses($excel, $metrics, $info);
        $this->orders($excel, $metrics, $info);

        return $excel;
    }

    public function download(): StreamedResponse
    {
        $name = 'laporan-keuangan-'.$this->period->from->toDateString().'_'.$this->period->to->toDateString().'.xlsx';

        return $this->build()->download($name);
    }

    /** @param  list<string>  $info */
    private function summary(WorkbookWriter $excel, FinanceMetrics $metrics, array $info): void
    {
        $sheet = $excel->sheet('Ringkasan');
        $row = $excel->title($sheet, 'Laporan Keuangan', $info);
        $s = $metrics->summary();
        $cash = $metrics->cashFlow();

        $row = $excel->keyValues($sheet, $row, 'Laba Rugi', [
            ['Penjualan kotor', $s['gross_sales']],
            ['Diskon', -$s['discount']],
            ['Refund (porsi penjualan)', -($s['gross_sales'] - $s['discount'] - $s['net_sales'])],
            ['Penjualan bersih', $s['net_sales'], 'money', true],
            ['HPP bahan (resep)', -$s['cogs']],
            ['Laba kotor', $s['gross_profit'], 'money', true],
            ['Margin laba kotor', $s['gross_margin'], 'percent'],
            ['Pengeluaran operasional', -$s['expenses']],
            ['Laba operasional', $s['operating_profit'], 'money', true],
        ]);

        $row = $excel->keyValues($sheet, $row, 'Arus Kas', [
            ['Diterima dari pelanggan', $cash['received']],
            ['Refund ke pelanggan', -$cash['refunded']],
            ['Pembayaran supplier', -$cash['supplier_payments']],
            ['Belanja cepat (tanpa PO)', -$cash['quick_purchases']],
            ['Pengeluaran operasional', -$cash['expenses']],
            ['Arus kas bersih', $cash['net'], 'money', true],
        ]);

        $row = $excel->keyValues($sheet, $row, 'Informasi Lain', [
            ['Jumlah transaksi', $s['orders'], 'int'],
            ['Rata-rata per transaksi', $s['average_order']],
            ['Pajak (dicatat terpisah)', $s['tax']],
            ['Service charge (dicatat terpisah)', $s['service_charge']],
            ['Void (jumlah)', $s['void_count'], 'int'],
            ['Void (nilai)', $s['void_amount']],
        ]);

        $excel->note($sheet, $row, 'Penjualan bersih = penjualan kotor − diskon − porsi refund. HPP dihitung dari resep saat transaksi. Arus kas berdasarkan tanggal uang diterima / dibayar.');
    }

    /** @param  list<string>  $info */
    private function daily(WorkbookWriter $excel, FinanceMetrics $metrics, array $info): void
    {
        $sheet = $excel->sheet('Penjualan Harian');
        $row = $excel->title($sheet, 'Penjualan Harian', $info);
        $days = $metrics->daily();

        $rows = collect($days)->map(fn (array $d, string $date) => [
            CarbonImmutable::parse($date)->translatedFormat('D, d M Y'), $d['orders'], $d['net_sales'], $d['total'],
        ])->values();

        $excel->table($sheet, $row, [['Tanggal', 'text'], ['Transaksi', 'int'], ['Penjualan bersih', 'money'], ['Total ditagih', 'money']], $rows,
            ['Total', $rows->sum(1), $rows->sum(2), $rows->sum(3)], freeze: true);
    }

    /** @param  list<string>  $info */
    private function payments(WorkbookWriter $excel, FinanceMetrics $metrics, array $info): void
    {
        $sheet = $excel->sheet('Metode Bayar');
        $row = $excel->title($sheet, 'Penerimaan per Metode Pembayaran', $info);

        $rows = collect($metrics->paymentsByMethod())->map(fn (array $p, string $method) => [
            PaymentMethod::tryFrom($method)?->label() ?? $method, $p['received'], $p['refunded'], $p['net'],
        ])->values();

        $excel->table($sheet, $row, [['Metode', 'text'], ['Diterima', 'money'], ['Refund', 'money'], ['Bersih', 'money']], $rows,
            ['Total', $rows->sum(1), $rows->sum(2), $rows->sum(3)]);
    }

    /** @param  list<string>  $info */
    private function expenses(WorkbookWriter $excel, FinanceMetrics $metrics, array $info): void
    {
        $sheet = $excel->sheet('Pengeluaran');
        $row = $excel->title($sheet, 'Pengeluaran Operasional', $info);
        $expenses = $metrics->expenses()->with(['category', 'outlet', 'user'])->orderBy('spent_at')->orderBy('id')->limit(WorkbookWriter::MAX_DETAIL_ROWS)->get();

        $byCategory = $expenses->groupBy(fn (Expense $e) => $e->category?->name ?? '—')
            ->map(fn ($items, $name) => [$name, $items->count(), (int) $items->sum('amount')])
            ->sortByDesc(2)->values();
        $row = $excel->table($sheet, $row, [['Kategori', 'text'], ['Catatan', 'int'], ['Total', 'money']], $byCategory,
            ['Total', $byCategory->sum(1), $byCategory->sum(2)], 'Per Kategori');

        $rows = $expenses->map(fn (Expense $e) => [
            $e->spent_at->format('d/m/Y'), $e->category?->name, $e->description, $e->outlet?->name ?? 'Biaya bersama', $e->method->label(), $e->user?->name, $e->amount,
        ]);
        $excel->table($sheet, $row, [['Tanggal', 'text'], ['Kategori', 'text'], ['Keterangan', 'text'], ['Outlet', 'text'], ['Dibayar via', 'text'], ['Dicatat oleh', 'text'], ['Nominal', 'money']],
            $rows, ['Total', '', '', '', '', '', (int) $expenses->sum('amount')], 'Rincian');
    }

    /** @param  list<string>  $info */
    private function orders(WorkbookWriter $excel, FinanceMetrics $metrics, array $info): void
    {
        $sheet = $excel->sheet('Pesanan');
        $row = $excel->title($sheet, 'Daftar Pesanan (transaksi sah)', $info);
        $orders = $metrics->sales()->with(['outlet', 'cashier', 'payments'])->orderBy('placed_at')->limit(WorkbookWriter::MAX_DETAIL_ROWS)->get();

        $rows = $orders->map(fn (Order $o) => [
            $o->number,
            $o->placed_at->timezone($this->period->timezone)->format('d/m/Y H:i'),
            $o->outlet?->name,
            $o->cashier?->name,
            $o->service_type->label(),
            $o->payments->map(fn ($p) => $p->method->label())->unique()->join(', '),
            $o->subtotal,
            $o->discount_amount,
            $o->service_charge_amount,
            $o->tax_amount,
            $o->total,
            (int) $o->refunded_amount,
        ]);

        $row = $excel->table($sheet, $row, [
            ['No. pesanan', 'text'], ['Waktu', 'text'], ['Outlet', 'text'], ['Kasir', 'text'], ['Layanan', 'text'], ['Metode bayar', 'text'],
            ['Subtotal', 'money'], ['Diskon', 'money'], ['Service', 'money'], ['Pajak', 'money'], ['Total', 'money'], ['Refund', 'money'],
        ], $rows, [
            'Total ('.$orders->count().' pesanan)', '', '', '', '', '',
            $orders->sum('subtotal'), $orders->sum('discount_amount'), $orders->sum('service_charge_amount'), $orders->sum('tax_amount'), $orders->sum('total'), (int) $orders->sum('refunded_amount'),
        ], freeze: true);

        if ($orders->count() >= WorkbookWriter::MAX_DETAIL_ROWS) {
            $excel->note($sheet, $row, 'Dibatasi '.number_format(WorkbookWriter::MAX_DETAIL_ROWS, 0, ',', '.').' pesanan pertama. Persempit periode untuk melihat semuanya.');
        }
    }
}
