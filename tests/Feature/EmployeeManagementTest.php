<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
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

    private function member(User $user): BusinessUser
    {
        return BusinessUser::where('business_id', $this->business->id)->where('user_id', $user->id)->sole();
    }

    public function test_only_owner_and_admin_can_open_employees_page(): void
    {
        $this->actingAs($this->owner)->get(route('employees.index'))->assertOk()->assertSee('Anggota Tim');

        $admin = User::factory()->role(UserRole::BusinessAdmin, $this->business)->create();
        $this->actingAs($admin)->get(route('employees.index'))->assertOk();

        $manager = User::factory()->role(UserRole::ManagerOutlet, $this->business)->create();
        $this->actingAs($manager)->get(route('employees.index'))->assertForbidden();
    }

    public function test_owner_adds_new_staff_with_outlet_assignment(): void
    {
        $this->actingAs($this->owner);

        Volt::test('pages.employees.index')
            ->call('create')
            ->set('name', 'Wati Kasir')
            ->set('email', 'wati.kasir@gmail.com')
            ->set('password', 'rahasia123')
            ->set('role', UserRole::Kasir->value)
            ->set('outletIds', [(string) $this->outletB->id])
            ->call('save')
            ->assertHasNoErrors();

        $wati = User::where('email', 'wati.kasir@gmail.com')->sole();
        $this->assertSame(UserRole::Kasir, $wati->currentRole());
        $this->assertSame([$this->outletB->id], $wati->accessibleOutlets()->pluck('id')->all());
        $this->assertNotNull($wati->email_verified_at);

        // Staf baru bisa login dengan password awal.
        Volt::test('pages.auth.login')
            ->set('form.email', 'wati.kasir@gmail.com')
            ->set('form.password', 'rahasia123')
            ->call('login')
            ->assertHasNoErrors();
    }

    public function test_existing_account_is_linked_instead_of_created(): void
    {
        $existing = User::factory()->create(['email' => 'sudah.ada@gmail.com']);
        $this->actingAs($this->owner);

        Volt::test('pages.employees.index')
            ->call('create')
            ->set('email', 'sudah.ada@gmail.com')
            ->set('role', UserRole::BusinessAdmin->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, User::where('email', 'sudah.ada@gmail.com')->count());
        $this->assertSame(UserRole::BusinessAdmin, $existing->fresh()->currentRole());
    }

    public function test_cannot_add_existing_member_twice(): void
    {
        $kasir = User::factory()->role(UserRole::Kasir, $this->business)->create();
        $this->actingAs($this->owner);

        Volt::test('pages.employees.index')
            ->call('create')
            ->set('email', $kasir->email)
            ->set('role', UserRole::Kasir->value)
            ->set('outletIds', [(string) $this->outletA->id])
            ->call('save')
            ->assertHasErrors('email');
    }

    public function test_staff_role_requires_an_outlet(): void
    {
        $this->actingAs($this->owner);

        Volt::test('pages.employees.index')
            ->call('create')
            ->set('name', 'Tanpa Outlet')
            ->set('email', 'tanpa.outlet@gmail.com')
            ->set('password', 'rahasia123')
            ->set('role', UserRole::Supervisor->value)
            ->set('outletIds', [])
            ->call('save')
            ->assertHasErrors('outletIds');
    }

    public function test_cannot_assign_outlet_from_another_business(): void
    {
        $foreignOutlet = Outlet::factory()->create();
        $this->actingAs($this->owner);

        Volt::test('pages.employees.index')
            ->call('create')
            ->set('name', 'Penyusup')
            ->set('email', 'penyusup@gmail.com')
            ->set('password', 'rahasia123')
            ->set('role', UserRole::Kasir->value)
            ->set('outletIds', [(string) $foreignOutlet->id])
            ->call('save')
            ->assertHasErrors('outletIds.0');
    }

    public function test_owner_role_can_never_be_assigned(): void
    {
        $this->actingAs($this->owner);

        Volt::test('pages.employees.index')
            ->call('create')
            ->set('name', 'Owner Kedua')
            ->set('email', 'owner.kedua@gmail.com')
            ->set('password', 'rahasia123')
            ->set('role', UserRole::Owner->value)
            ->call('save')
            ->assertHasErrors('role');
    }

    public function test_admin_cannot_manage_owner_or_other_admins_or_grant_admin(): void
    {
        $admin = User::factory()->role(UserRole::BusinessAdmin, $this->business)->create();
        $otherAdmin = User::factory()->role(UserRole::BusinessAdmin, $this->business)->create();
        $this->actingAs($admin);

        Volt::test('pages.employees.index')->call('edit', $this->member($this->owner)->id)->assertForbidden();
        Volt::test('pages.employees.index')->call('toggleActive', $this->member($otherAdmin)->id)->assertForbidden();

        Volt::test('pages.employees.index')
            ->call('create')
            ->set('name', 'Admin Baru')
            ->set('email', 'admin.baru@gmail.com')
            ->set('password', 'rahasia123')
            ->set('role', UserRole::BusinessAdmin->value)
            ->call('save')
            ->assertHasErrors('role');
    }

    public function test_nobody_can_edit_or_deactivate_themselves(): void
    {
        $this->actingAs($this->owner);

        Volt::test('pages.employees.index')->call('toggleActive', $this->member($this->owner)->id)->assertForbidden();
    }

    public function test_cannot_touch_members_of_another_business(): void
    {
        $foreign = User::factory()->role(UserRole::Kasir)->create();
        $this->actingAs($this->owner);

        $foreignMember = BusinessUser::where('user_id', $foreign->id)->sole();

        Volt::test('pages.employees.index')
            ->call('edit', $foreignMember->id)
            ->assertNotFound();
    }

    public function test_owner_changes_role_and_outlets(): void
    {
        $staff = User::factory()->role(UserRole::Kasir, $this->business)->create();
        $this->actingAs($this->owner);

        Volt::test('pages.employees.index')
            ->call('edit', $this->member($staff)->id)
            ->assertSet('role', UserRole::Kasir->value)
            ->set('role', UserRole::Supervisor->value)
            ->set('outletIds', [(string) $this->outletA->id, (string) $this->outletB->id])
            ->call('save')
            ->assertHasNoErrors();

        $staff = $staff->fresh();
        $this->assertSame(UserRole::Supervisor, $staff->currentRole());
        $this->assertCount(2, $staff->accessibleOutlets());
    }

    public function test_deactivated_staff_loses_web_and_api_access(): void
    {
        $kasir = User::factory()->role(UserRole::Kasir, $this->business)->create();
        $kasir->createToken('Tablet', ['pos']);
        $this->actingAs($this->owner);

        Volt::test('pages.employees.index')->call('toggleActive', $this->member($kasir)->id);

        $this->assertFalse($this->member($kasir)->is_active);
        $this->assertSame(0, $kasir->tokens()->count());

        // Web: dikeluarkan ke halaman login, tidak dilempar ke onboarding.
        $this->actingAs($kasir->fresh())
            ->get('/dashboard')
            ->assertRedirect(route('login'));
        $this->assertGuest();

        // API: login ditolak.
        $this->postJson('/api/v1/auth/login', [
            'email' => $kasir->email,
            'password' => 'password',
            'device_name' => 'Tablet',
        ])->assertForbidden();
    }

    public function test_deactivated_staff_cannot_create_own_business_via_onboarding(): void
    {
        $kasir = User::factory()->role(UserRole::Kasir, $this->business)->create();
        $this->member($kasir)->update(['is_active' => false]);

        $this->actingAs($kasir->fresh())->get(route('onboarding'))->assertForbidden();
    }

    public function test_business_owner_cannot_delete_own_account(): void
    {
        $this->actingAs($this->owner);

        Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors('password');

        $this->assertNotNull($this->owner->fresh());
    }

    public function test_staff_can_still_delete_own_account(): void
    {
        $kasir = User::factory()->role(UserRole::Kasir, $this->business)->create();
        $this->actingAs($kasir);

        Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($kasir->fresh());
    }
}
