<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class MenuManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Business $business;

    private Outlet $outletA;

    private Outlet $outletB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->role(UserRole::Owner)->create();
        $this->business = $this->owner->activeBusiness();
        $this->outletA = $this->business->outlets()->first();
        $this->outletB = Outlet::factory()->for($this->business)->create();
    }

    public function test_menu_pages_require_menu_permission(): void
    {
        $kasir = User::factory()->role(UserRole::Kasir, $this->business)->create();

        foreach (['menu.items.index', 'menu.items.create', 'menu.categories', 'menu.modifiers'] as $route) {
            $this->actingAs($this->owner)->get(route($route))->assertOk();
            $this->actingAs($kasir)->get(route($route))->assertForbidden();
        }
    }

    public function test_owner_creates_menu_with_variants_modifiers_and_outlet_prices(): void
    {
        Storage::fake('public');
        $category = MenuCategory::factory()->for($this->business)->create(['name' => 'Kopi']);
        $sugar = ModifierGroup::factory()->for($this->business)->pickOne()->create(['name' => 'Level Gula']);

        $this->actingAs($this->owner);

        Volt::test('pages.menu.items.form')
            ->set('name', 'Kopi Susu')
            ->set('categoryId', (string) $category->id)
            ->set('sku', 'KS-01')
            ->set('price', '20000')
            ->set('photo', UploadedFile::fake()->image('kopi.jpg'))
            ->call('addVariant')->set('variants.0.name', 'Regular')->set('variants.0.price_adjustment', '0')
            ->call('addVariant')->set('variants.1.name', 'Large')->set('variants.1.price_adjustment', '5000')
            ->set('modifierGroupIds', [(string) $sugar->id])
            ->set("outletSettings.{$this->outletB->id}.price", '18000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('menu.items.index'));

        $item = MenuItem::with(['variants', 'modifierGroups', 'outlets'])->where('sku', 'KS-01')->sole();
        $this->assertSame(['Regular', 'Large'], $item->variants->pluck('name')->all());
        $this->assertTrue($item->modifierGroups->first()->is($sugar));
        $this->assertSame(20000, $item->priceAt($this->outletA));
        $this->assertSame(18000, $item->priceAt($this->outletB));
        // Outlet tanpa pengaturan khusus tidak perlu baris.
        $this->assertSame([$this->outletB->id], $item->outlets->pluck('id')->all());
        Storage::disk('public')->assertExists($item->image_path);
    }

    public function test_menu_validation(): void
    {
        MenuItem::factory()->for($this->business)->create(['sku' => 'DUP-1']);
        $foreignCategory = MenuCategory::factory()->create();

        $this->actingAs($this->owner);

        Volt::test('pages.menu.items.form')
            ->set('name', '')
            ->set('price', '-1')
            ->set('sku', 'DUP-1')
            ->set('categoryId', (string) $foreignCategory->id)
            ->call('addVariant')->call('addVariant')
            ->set('variants.0.name', 'Large')->set('variants.1.name', 'large')
            ->call('save')
            ->assertHasErrors(['name', 'price', 'sku', 'categoryId', 'variants.1.name']);

        $this->assertSame(1, MenuItem::count());
    }

    public function test_editing_removes_dropped_variants_and_clears_outlet_override(): void
    {
        $item = MenuItem::factory()->for($this->business)->create(['price' => 15000]);
        $item->variants()->createMany([['name' => 'Kecil'], ['name' => 'Besar', 'price_adjustment' => 3000]]);
        $item->outlets()->attach($this->outletA->id, ['price' => 12000, 'is_available' => true]);

        $this->actingAs($this->owner);

        Volt::test('pages.menu.items.form', ['menuItem' => $item])
            ->assertSet("outletSettings.{$this->outletA->id}.price", '12000')
            ->call('removeVariant', 0)
            ->set("outletSettings.{$this->outletA->id}.price", '')
            ->set("outletSettings.{$this->outletB->id}.available", false)
            ->call('save')
            ->assertHasNoErrors();

        $item->refresh()->load('outlets');
        $this->assertSame(['Besar'], $item->variants()->pluck('name')->all());
        $this->assertSame(15000, $item->priceAt($this->outletA));
        $this->assertTrue($item->isAvailableAt($this->outletA));
        $this->assertFalse($item->isAvailableAt($this->outletB));
    }

    public function test_manager_only_changes_settings_of_assigned_outlets(): void
    {
        $manager = User::factory()->role(UserRole::ManagerOutlet, $this->business)->create();
        $manager->memberships()->sole()->outlets()->sync([$this->outletA->id]);
        $item = MenuItem::factory()->for($this->business)->create(['price' => 15000]);
        $item->outlets()->attach($this->outletB->id, ['price' => 17000, 'is_available' => true]);

        $this->actingAs($manager);

        Volt::test('pages.menu.items.form', ['menuItem' => $item])
            ->assertDontSee($this->outletB->name)
            ->set("outletSettings.{$this->outletA->id}.price", '14000')
            ->set("outletSettings.{$this->outletB->id}", ['available' => false, 'price' => '1'])
            ->call('save')
            ->assertHasNoErrors();

        $item->refresh()->load('outlets');
        $this->assertSame(14000, $item->priceAt($this->outletA));
        $this->assertSame(17000, $item->priceAt($this->outletB));
        $this->assertTrue($item->isAvailableAt($this->outletB));
    }

    public function test_menu_of_another_business_cannot_be_opened(): void
    {
        $foreign = MenuItem::factory()->create();

        $this->actingAs($this->owner)->get(route('menu.items.edit', $foreign))->assertNotFound();
    }

    public function test_marking_menu_sold_out_at_current_outlet(): void
    {
        $item = MenuItem::factory()->for($this->business)->create();
        $this->owner->switchOutlet($this->outletA);

        $this->actingAs($this->owner);
        $page = Volt::test('pages.menu.items.index')->call('toggleAvailability', $item->id);

        $this->assertFalse($item->fresh()->isAvailableAt($this->outletA));
        $this->assertTrue($item->fresh()->isAvailableAt($this->outletB));

        $page->call('toggleAvailability', $item->id);
        $this->assertTrue($item->fresh()->isAvailableAt($this->outletA));
    }

    public function test_categories_are_unique_per_business_and_deleting_keeps_menu(): void
    {
        MenuCategory::factory()->create(['name' => 'Kopi']); // bisnis lain boleh memakai nama yang sama

        $this->actingAs($this->owner);
        $page = Volt::test('pages.menu.categories')->call('create')->set('name', 'Kopi')->call('save')->assertHasNoErrors();
        $page->call('create')->set('name', 'Kopi')->call('save')->assertHasErrors(['name' => 'unique']);

        $category = $this->business->menuCategories()->sole();
        $item = MenuItem::factory()->for($this->business)->create(['menu_category_id' => $category->id]);

        $page->call('delete', $category->id);

        $this->assertModelMissing($category);
        $this->assertNull($item->fresh()->menu_category_id);
    }

    public function test_modifier_group_saves_rules_and_syncs_options(): void
    {
        $this->actingAs($this->owner);

        $page = Volt::test('pages.menu.modifiers')
            ->call('create')
            ->set('name', 'Topping')
            ->set('minSelect', '0')
            ->set('maxSelect', '')
            ->set('options.0.name', 'Keju')->set('options.0.price', '5000')
            ->set('options.1.name', 'Telur')->set('options.1.price', '4000')
            ->call('save')
            ->assertHasNoErrors();

        $group = $this->business->modifierGroups()->with('modifiers')->sole();
        $this->assertNull($group->max_select);
        $this->assertSame(['Keju' => 5000, 'Telur' => 4000], $group->modifiers->pluck('price', 'name')->all());

        $page->call('edit', $group->id)->call('removeOption', 1)->set('minSelect', '2')->call('save')
            ->assertHasErrors('minSelect');

        $page->set('minSelect', '1')->set('maxSelect', '1')->call('save')->assertHasNoErrors();
        $this->assertSame(['Keju'], $group->modifiers()->pluck('name')->all());
        $this->assertSame('Wajib pilih 1', $group->fresh()->ruleLabel());
    }
}
