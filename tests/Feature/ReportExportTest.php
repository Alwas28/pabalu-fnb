<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\ShiftManager;
use App\Support\Reports\FinanceMetrics;
use App\Support\Reports\Period;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Outlet $kendari;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'pabalu.owner@gmail.com')->sole();
        $this->kendari = Outlet::where('code', 'KDI')->sole();

        $kasir = User::where('email', 'pabalu.kasir@gmail.com')->sole();
        app(ShiftManager::class)->open($kasir, $this->kendari, ['opening_cash' => 0]);
        app(PlaceOrder::class)->handle($kasir, $this->kendari, [
            'service_type' => 'takeaway',
            'items' => [['menu_item_id' => MenuItem::where('sku', 'CML-002')->value('id'), 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 200000]],
        ]);
    }

    private function workbook(TestResponse $response): Spreadsheet
    {
        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());

        return tap(IOFactory::load($path), fn () => @unlink($path));
    }

    /** Nilai kolom B untuk label di kolom A (lembar ringkasan). */
    private function valueFor(Worksheet $sheet, string $label): mixed
    {
        foreach ($sheet->getRowIterator() as $row) {
            if ($sheet->getCell('A'.$row->getRowIndex())->getValue() === $label) {
                return $sheet->getCell('B'.$row->getRowIndex())->getValue();
            }
        }

        $this->fail("Label [{$label}] tidak ditemukan.");
    }

    public function test_finance_workbook_matches_finance_metrics(): void
    {
        $today = now($this->kendari->business->timezone)->toDateString();
        $book = $this->workbook($this->actingAs($this->owner)->get(route('finance.export', ['from' => $today, 'to' => $today, 'outlet' => $this->kendari->id])));

        $this->assertSame(['Ringkasan', 'Penjualan Harian', 'Metode Bayar', 'Pengeluaran', 'Pesanan'], $book->getSheetNames());

        $summary = (new FinanceMetrics($this->kendari->business_id, [$this->kendari->id], Period::make($today, $today, $this->kendari->business->timezone), false))->summary();
        $sheet = $book->getSheetByName('Ringkasan');
        $this->assertEquals($summary['net_sales'], $this->valueFor($sheet, 'Penjualan bersih'));
        $this->assertEquals($summary['operating_profit'], $this->valueFor($sheet, 'Laba operasional'));
        $this->assertEquals(1, $this->valueFor($sheet, 'Jumlah transaksi'));
        $this->assertStringContainsString('Outlet Kendari', (string) $sheet->getCell('A2')->getValue());

        // Baris 1–4 judul & info, baris 6 header, baris 7 pesanan pertama.
        $this->assertSame('No. pesanan', $book->getSheetByName('Pesanan')->getCell('A6')->getValue());
        $this->assertStringStartsWith('KDI-', (string) $book->getSheetByName('Pesanan')->getCell('A7')->getValue());
    }

    public function test_stock_workbook_closing_balance_matches_ledger(): void
    {
        $today = now($this->kendari->business->timezone)->toDateString();
        $book = $this->workbook($this->actingAs($this->owner)->get(route('inventory.export', ['from' => $today, 'to' => $today, 'outlet' => $this->kendari->id])));

        $this->assertSame(['Saldo Persediaan', 'Mutasi Stok', 'Rincian Mutasi'], $book->getSheetNames());

        // Kentang Beku: saldo awal + mutasi hari ini (saldo awal seeder & pemakaian resep) = saldo ledger.
        $sheet = $book->getSheetByName('Mutasi Stok');
        $fries = Ingredient::where('name', 'Kentang Beku')->sole();
        $expected = app(StockLedger::class)->quantity($this->kendari, $fries);
        $found = false;
        foreach ($sheet->getRowIterator() as $row) {
            $r = $row->getRowIndex();
            if ($sheet->getCell("B{$r}")->getValue() === 'Kentang Beku') {
                $highest = $sheet->getHighestDataColumn($r);
                $this->assertEqualsWithDelta($expected, (float) $sheet->getCell("{$highest}{$r}")->getValue(), 0.001);
                $found = true;
            }
        }
        $this->assertTrue($found, 'Baris Kentang Beku tidak ada di Mutasi Stok.');
    }

    public function test_exports_respect_permissions_and_outlet_access(): void
    {
        $manager = User::where('email', 'pabalu.manager@gmail.com')->sole();
        $kasir = User::where('email', 'pabalu.kasir@gmail.com')->sole();

        $this->actingAs($manager)->get(route('finance.export'))->assertForbidden();
        $this->actingAs($kasir)->get(route('inventory.export'))->assertForbidden();

        // Manajer Kendari tidak boleh mengunduh stok outlet lain.
        $this->actingAs($manager)->get(route('inventory.export', ['outlet' => Outlet::where('code', 'BAU')->value('id')]))->assertSessionHasErrors('outlet');
        $this->workbook($this->actingAs($manager)->get(route('inventory.export')));
    }
}
