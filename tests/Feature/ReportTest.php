<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\ShiftManager;
use App\Support\Reports\Period;
use App\Support\Reports\ReportBuilder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $kendari;

    private Outlet $baubau;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->kendari = Outlet::where('code', 'KDI')->sole();
        $this->baubau = Outlet::where('code', 'BAU')->sole();

        // Kendari: 3 air mineral (6.000) ; Baubau: 1 kentang goreng (15.000)
        $this->sell('pabalu.kasir@gmail.com', $this->kendari, 'NKP-003', 3);
        $this->sell('pabalu.supervisor@gmail.com', $this->baubau, 'CML-002', 1);
    }

    private function sell(string $email, Outlet $outlet, string $sku, int $quantity): void
    {
        $user = User::where('email', $email)->sole();
        app(ShiftManager::class)->open($user, $outlet, ['opening_cash' => 0]);
        app(PlaceOrder::class)->handle($user, $outlet, [
            'service_type' => 'takeaway',
            'items' => [['menu_item_id' => MenuItem::where('sku', $sku)->value('id'), 'quantity' => $quantity]],
            'payments' => [['method' => 'cash', 'amount' => 100000]],
        ]);
    }

    private function builder(array $outletIds): ReportBuilder
    {
        return new ReportBuilder($this->kendari->business_id, $outletIds, Period::make(null, null, 'Asia/Makassar'));
    }

    /** @return array<string, array{string}> */
    public static function reports(): array
    {
        return collect(ReportBuilder::REPORTS)->keys()->mapWithKeys(fn ($k) => [$k => [$k]])->all();
    }

    #[DataProvider('reports')]
    public function test_every_report_builds_a_consistent_table(string $report): void
    {
        $table = $this->builder([$this->kendari->id, $this->baubau->id])->build($report);
        $this->assertNotEmpty($table['columns']);

        foreach ($table['rows'] as $row) {
            $this->assertCount(count($table['columns']), $row);
        }
    }

    public function test_sales_reports_match_definitions(): void
    {
        $all = $this->builder([$this->kendari->id, $this->baubau->id]);

        $byOutlet = collect($all->build('outlet')['rows'])->keyBy(0);
        $this->assertSame(18000, $byOutlet['Outlet Kendari'][2]);
        $this->assertSame(15000, $byOutlet['Outlet Baubau'][2]);

        $menu = $all->build('menu');
        $this->assertSame('Air Mineral', $menu['rows'][0][1]); // terlaris berdasarkan jumlah
        $this->assertSame(3, $menu['rows'][0][2]);

        $this->assertSame(33000, $all->build('daily')['totals'][2]);
        $this->assertContains('Croissant Cokelat', array_column($all->build('unsold')['rows'], 0));
    }

    public function test_manager_only_sees_assigned_outlets_and_can_export(): void
    {
        $manager = User::where('email', 'pabalu.manager@gmail.com')->sole(); // Kendari
        $this->actingAs($manager)->get(route('reports.index', ['report' => 'outlet', 'outlet' => $this->baubau->id]))
            ->assertOk()
            ->assertSee('Outlet Kendari')
            ->assertDontSee('Outlet Baubau</td>', false);

        $this->actingAs(User::where('email', 'pabalu.kasir@gmail.com')->sole())->get(route('reports.index'))->assertForbidden();

        $this->actingAs($manager);
        Volt::test('pages.reports.index')->set('report', 'menu')->call('export')->assertFileDownloaded();
    }
}
