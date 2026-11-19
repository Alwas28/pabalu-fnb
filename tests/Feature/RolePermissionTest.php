<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\MenuItem;
use App\Models\Outlet;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.role_permissions' => true]);
        $this->seed(DatabaseSeeder::class);
        $this->business = Business::where('name', 'Pabalu Coffee & Kitchen')->sole();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->sole();
    }

    public function test_only_owner_can_open_role_settings(): void
    {
        $this->actingAs($this->user('pabalu.owner@gmail.com'))->get(route('settings.roles'))->assertOk()->assertSee('Hak Akses Role');
        $this->actingAs($this->user('pabalu.admin@gmail.com'))->get(route('settings.roles'))->assertForbidden();
    }

    public function test_role_settings_are_hidden_and_ignored_when_feature_is_off(): void
    {
        config(['features.role_permissions' => false]);
        $this->business->update(['role_permissions' => [UserRole::Kasir->value => ['orders.view']]]);
        $owner = $this->user('pabalu.owner@gmail.com');

        $this->actingAs($owner)->get(route('settings.roles'))->assertNotFound();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertDontSee('Hak Akses Role');
        $this->assertTrue($this->user('pabalu.kasir@gmail.com')->hasPermission(Permission::UsePos));
    }

    public function test_owner_grants_and_revokes_permissions_per_role(): void
    {
        $this->actingAs($this->user('pabalu.owner@gmail.com'));

        Volt::test('pages.settings.roles')
            ->set('grants.kasir.ManageMenu', true)
            ->set('grants.kasir.UsePos', false)
            ->call('save')
            ->assertHasNoErrors();

        $kasir = $this->user('pabalu.kasir@gmail.com');
        $this->actingAs($kasir)->get(route('menu.items.index'))->assertOk();
        $this->actingAs($kasir)->get(route('pos'))->assertForbidden();

        $this->postJson('/api/v1/auth/login', ['email' => $kasir->email, 'password' => 'password', 'device_name' => 'Tablet'])->assertForbidden();

        // Role lain tidak terpengaruh; perubahan tercatat.
        $this->assertTrue($this->business->fresh()->roleHasPermission(UserRole::Supervisor, Permission::UsePos));
        $log = AuditLog::where('event', 'permissions')->sole();
        $this->assertArrayHasKey('Kasir', $log->changes);
    }

    public function test_owner_only_permissions_cannot_be_granted(): void
    {
        $this->business->update(['role_permissions' => [UserRole::BusinessAdmin->value => ['refunds.approve', 'roles.manage', 'menu.manage']]]);

        $admin = $this->user('pabalu.admin@gmail.com');
        $this->assertFalse($admin->fresh()->hasPermission(Permission::ApproveRefunds));
        $this->assertFalse($admin->fresh()->hasPermission(Permission::ManageRoles));
        $this->assertTrue($admin->fresh()->hasPermission(Permission::ManageMenu));
        $this->assertFalse($admin->fresh()->hasPermission(Permission::ManageEmployees)); // tidak dicentang
        $this->assertTrue($this->user('pabalu.owner@gmail.com')->hasPermission(Permission::ManageRoles));
    }

    public function test_resetting_a_role_restores_defaults(): void
    {
        $this->business->update(['role_permissions' => [UserRole::Kasir->value => ['orders.view']]]);
        $this->actingAs($this->user('pabalu.owner@gmail.com'));

        Volt::test('pages.settings.roles')->call('resetRole', 'kasir')->call('save');

        $this->assertNull($this->business->fresh()->role_permissions);
        $this->assertTrue($this->user('pabalu.kasir@gmail.com')->hasPermission(Permission::UsePos));
    }

    public function test_price_recipe_and_role_changes_are_audited(): void
    {
        $owner = $this->user('pabalu.owner@gmail.com');
        $this->actingAs($owner);
        $kopi = MenuItem::where('sku', 'KOP-001')->sole();
        $baubau = Outlet::where('code', 'BAU')->sole();

        Volt::test('pages.menu.items.form', ['menuItem' => $kopi])
            ->set('price', '23000')
            ->set("outletSettings.{$baubau->id}.price", '21000')
            ->call('save')
            ->assertHasNoErrors();

        $priceLog = AuditLog::where('auditable_id', $kopi->id)->where('auditable_type', MenuItem::class)->where('event', 'updated')->sole();
        $this->assertSame([22000, 23000], $priceLog->changes['price']);
        $this->assertTrue($priceLog->user->is($owner));

        $outletLog = AuditLog::where('event', 'outlet_price')->sole();
        $this->assertSame(['Rp 20.000', 'Rp 21.000'], $outletLog->changes['Outlet Baubau']);

        Volt::test('pages.recipes.index')
            ->call('editRecipe', 'menu', $kopi->id)
            ->call('removeRecipeRow', 0)
            ->call('saveRecipe')
            ->assertHasNoErrors();
        $this->assertSame(1, AuditLog::where('event', 'recipe')->count());

        $membership = $this->user('pabalu.kasir@gmail.com')->memberships()->sole();
        $membership->update(['role' => UserRole::Supervisor]);
        $this->assertSame(['kasir', 'supervisor'], AuditLog::where('auditable_type', BusinessUser::class)->latest('id')->first()->changes['role']);

        $this->get(route('settings.audit'))->assertOk()->assertSee('Kopi Susu Gula Aren');
        $this->actingAs($this->user('pabalu.kasir@gmail.com'))->get(route('settings.audit'))->assertForbidden();
    }
}
