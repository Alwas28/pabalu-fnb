<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureBusinessContext;
use App\Models\AdminActivity;
use App\Models\Business;
use App\Models\ExpenseCategoryTemplate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', UserSeeder::SUPER_ADMIN['email'])->sole();
        $this->business = Business::where('name', 'Pabalu Coffee & Kitchen')->sole();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->sole();
    }

    public function test_only_super_admin_can_open_the_admin_panel(): void
    {
        Volt::test('pages.auth.login')
            ->set('form.email', $this->admin->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertRedirect(route('admin.dashboard', absolute: false));

        foreach (['admin.dashboard', 'admin.businesses.index', 'admin.users.index', 'admin.roles', 'admin.activity'] as $route) {
            $this->actingAs($this->admin)->get(route($route))->assertOk();
        }

        $this->get(route('admin.businesses.show', $this->business))->assertOk()->assertSee('Ahmad Fulan');
        $this->get(route('admin.api-docs'))->assertOk()->assertSee('API Kasir Pabalu v1')->assertSee(url('/api/v1'));
        $this->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));

        $owner = $this->user('pabalu.owner@gmail.com');
        $this->actingAs($owner)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($owner)->get(route('admin.api-docs'))->assertForbidden();
    }

    public function test_deactivated_owner_is_locked_out_of_web_and_pos_api(): void
    {
        $owner = $this->user('pabalu.owner@gmail.com');
        $token = $owner->createToken('Tablet', ['pos'])->plainTextToken;

        $this->actingAs($this->admin);
        Volt::test('pages.admin.users')
            ->call('openDeactivate', $owner->id)
            ->call('deactivate')
            ->assertHasErrors('reason')
            ->set('reason', 'Langganan berakhir')
            ->call('deactivate')
            ->assertHasNoErrors();

        $owner->refresh();
        $this->assertTrue($owner->isDeactivated());
        $this->assertSame(0, $owner->tokens()->count());
        $this->assertSame('Langganan berakhir', AdminActivity::where('action', 'user.deactivated')->sole()->details['alasan']);

        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        Volt::test('pages.auth.login')
            ->set('form.email', $owner->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasErrors('form.email');
        $this->assertGuest();

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password', 'device_name' => 'Tablet'])
            ->assertForbidden()->assertJsonPath('message', EnsureAccountIsActive::MESSAGE);
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

        // Token lama yang lolos tetap ditolak selama akun nonaktif.
        Sanctum::actingAs($owner, ['pos']);
        $this->getJson('/api/v1/me')->assertForbidden();

        $this->actingAs($this->admin);
        Volt::test('pages.admin.users')->call('activate', $owner->id);
        $this->assertFalse($owner->fresh()->isDeactivated());
        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_super_admin_accounts_cannot_be_deactivated_from_the_panel(): void
    {
        $this->actingAs($this->admin);

        Volt::test('pages.admin.users')
            ->call('openDeactivate', $this->admin->id)
            ->set('reason', 'Uji')
            ->call('deactivate')
            ->assertHasErrors('user');

        $this->assertFalse($this->admin->fresh()->isDeactivated());
    }

    public function test_suspending_a_business_blocks_all_members_until_reactivated(): void
    {
        $kasir = $this->user('pabalu.kasir@gmail.com');

        $this->actingAs($this->admin);
        Volt::test('pages.admin.businesses.show', ['business' => $this->business])
            ->call('openSuspend')
            ->set('reason', 'Tagihan belum dibayar')
            ->call('confirm')
            ->assertHasNoErrors();
        $this->assertTrue($this->business->fresh()->isSuspended());

        $this->actingAs($kasir)->get(route('pos'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', EnsureBusinessContext::SUSPENDED_MESSAGE);

        Sanctum::actingAs($kasir->fresh(), ['pos']);
        $this->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('message', EnsureBusinessContext::SUSPENDED_MESSAGE);

        $this->actingAs($this->admin);
        Volt::test('pages.admin.businesses.show', ['business' => $this->business->fresh()])->call('reactivate');
        $this->actingAs($kasir->fresh())->get(route('pos'))->assertOk();
    }

    public function test_role_permissions_apply_to_every_business_and_can_be_reset(): void
    {
        $this->actingAs($this->admin);
        Volt::test('pages.admin.roles')
            ->set('grants.kasir.UsePos', false)
            ->set('grants.kasir.ViewReports', true)
            ->call('save');

        $kasir = $this->user('pabalu.kasir@gmail.com');
        $this->assertFalse($kasir->hasPermission(Permission::UsePos));
        $this->actingAs($kasir)->get(route('reports.index'))->assertOk();
        $this->actingAs($kasir)->get(route('pos'))->assertForbidden();
        $this->assertSame(1, AdminActivity::where('action', 'roles.updated')->count());

        $this->actingAs($this->admin);
        Volt::test('pages.admin.roles')->call('resetRole', 'kasir')->call('save');
        $this->assertTrue($this->user('pabalu.kasir@gmail.com')->hasPermission(Permission::UsePos));
    }

    public function test_command_creates_a_super_admin(): void
    {
        $this->artisan('pabalu:super-admin', ['email' => 'ops@pabalu.id', '--name' => 'Ops'])->assertSuccessful();
        $this->assertTrue(User::where('email', 'ops@pabalu.id')->sole()->isSuperAdmin());

        $this->artisan('pabalu:super-admin', ['email' => 'pabalu.owner@gmail.com'])->assertFailed();
        $this->assertFalse($this->user('pabalu.owner@gmail.com')->isSuperAdmin());
    }

    public function test_super_admin_manages_expense_categories_for_all_businesses(): void
    {
        $owner = $this->user('pabalu.owner@gmail.com');
        $categories = fn () => $this->business->expenseCategories();

        $this->actingAs($owner);
        $ownerPage = fn () => Volt::test('pages.expenses.index');
        $ownerPage();
        $this->assertTrue($categories()->where('name', 'Bahan Baku')->exists());

        // Owner mencatat di kategori bawaan & kategori buatannya sendiri.
        $record = fn (array $input) => $ownerPage()->dispatch('expense-open')
            ->set($input + ['amount' => '10000', 'description' => 'Catatan'])
            ->call('saveExpense')->assertHasNoErrors();
        $record(['categoryId' => (string) $categories()->where('name', 'Lain-lain')->value('id')]);
        $record(['newCategory' => 'Lainnya']);
        $record(['newCategory' => 'Kemasan']);
        $ownCount = $categories()->count();

        $this->actingAs($this->admin);
        $admin = Volt::test('pages.admin.expense-categories');

        // Kategori baru bernama sama dengan milik owner ditautkan, bukan diduplikasi.
        $admin->set('newName', 'Kemasan')->call('create')->assertHasNoErrors();
        $admin->set('newName', 'kemasan baru')->call('create')->assertHasNoErrors();
        $admin->set('newName', 'Kemasan')->call('create')->assertHasErrors('name');

        // Ganti nama yang bentrok dengan kategori owner: pengeluarannya digabung.
        $lainLain = ExpenseCategoryTemplate::where('name', 'Lain-lain')->sole();
        $admin->call('openRename', $lainLain->id)->set('renameTo', 'Lainnya')->call('rename')->assertHasNoErrors();

        $pemasaran = ExpenseCategoryTemplate::where('name', 'Pemasaran')->sole();
        $admin->call('toggle', $pemasaran->id);
        $this->assertFalse($pemasaran->fresh()->is_active);

        $this->actingAs($owner);
        $page = $ownerPage();

        $this->assertSame($ownCount, $categories()->count()); // + "kemasan baru", − "Lain-lain" yang digabung
        $this->assertNotNull($categories()->where('name', 'Kemasan')->value('template_id'));
        $this->assertFalse($categories()->where('name', 'Lain-lain')->exists());
        $this->assertSame(2, $categories()->where('name', 'Lainnya')->sole()->expenses()->count());

        $pemasaranId = $categories()->where('name', 'Pemasaran')->value('id');
        $this->assertFalse($page->get('formCategories')->contains('id', $pemasaranId));
        $page->dispatch('expense-open')
            ->set(['categoryId' => (string) $pemasaranId, 'amount' => '10000', 'description' => 'Brosur'])
            ->call('saveExpense')
            ->assertHasErrors('categoryId');

        $this->assertSame(1, AdminActivity::where('action', 'expense_category.renamed')->count());
        $this->assertSame(1, AdminActivity::where('action', 'expense_category.deactivated')->count());
    }
}
