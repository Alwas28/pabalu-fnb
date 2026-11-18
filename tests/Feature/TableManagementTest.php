<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TableManagementTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $kendari;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->kendari = Outlet::where('code', 'KDI')->sole();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->sole();
    }

    public function test_tables_page_requires_table_permission(): void
    {
        $this->actingAs($this->user('pabalu.manager@gmail.com'))->get(route('tables.index'))->assertOk()->assertSee('A1');
        $this->actingAs($this->user('pabalu.kasir@gmail.com'))->get(route('tables.index'))->assertForbidden();
    }

    public function test_bulk_create_rejects_existing_names(): void
    {
        $this->actingAs($this->user('pabalu.manager@gmail.com'));

        Volt::test('pages.tables.index')
            ->set('bulkPrefix', 'A')->set('bulkStart', '5')->set('bulkCount', '3')
            ->call('saveBulk')
            ->assertHasErrors('bulkStart');

        Volt::test('pages.tables.index')
            ->set('bulkArea', 'Lantai 2')->set('bulkPrefix', 'C')->set('bulkStart', '1')->set('bulkCount', '3')
            ->call('saveBulk')
            ->assertHasNoErrors();

        $this->assertSame(['C1', 'C2', 'C3'], $this->kendari->diningTables()->where('area', 'Lantai 2')->pluck('name')->sort()->values()->all());
    }

    public function test_single_table_name_is_unique_per_outlet(): void
    {
        $this->actingAs($this->user('pabalu.manager@gmail.com'));

        Volt::test('pages.tables.index')->call('create')->set('name', 'A1')->call('save')->assertHasErrors(['name' => 'unique']);
        Volt::test('pages.tables.index')->call('create')->set('name', 'VIP')->set('capacity', '10')->call('save')->assertHasNoErrors();

        $this->assertSame(10, $this->kendari->diningTables()->where('name', 'VIP')->sole()->capacity);
    }

    public function test_occupied_table_can_be_cleared_manually(): void
    {
        $table = $this->kendari->diningTables()->where('name', 'A1')->sole();
        $table->forceFill(['occupied_at' => now()])->save();

        $this->actingAs($this->user('pabalu.manager@gmail.com'));
        Volt::test('pages.tables.index')->call('clear', $table->id);

        $this->assertFalse($table->fresh()->isOccupied());
    }
}
