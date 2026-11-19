<?php

namespace Tests\Feature;

use App\Enums\KitchenMode;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_one_verified_member_per_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Business::count());
        $this->assertSame(count(UserRole::cases()), User::where('is_super_admin', false)->count());
        $this->assertTrue(User::where('email', UserSeeder::SUPER_ADMIN['email'])->sole()->isSuperAdmin());

        foreach (UserSeeder::accounts() as $account) {
            $user = User::where('email', $account['email'])->sole();

            $this->assertNotNull($user->email_verified_at);
            $this->assertSame($account['role'], $user->currentRole());
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(count(UserSeeder::accounts()) + 1, User::count()); // + super admin
        $this->assertSame(1, Business::count());
        $this->assertSame(3, Outlet::count());
    }

    public function test_seeded_outlets_have_different_settings(): void
    {
        $this->seed(DatabaseSeeder::class);

        $kendari = Outlet::where('code', 'KDI')->sole();
        $kolaka = Outlet::where('code', 'KLK')->sole();

        $this->assertTrue($kendari->usesTables());
        $this->assertSame(KitchenMode::Display, $kendari->kitchen_mode);
        $this->assertTrue($kendari->offers(ServiceType::Delivery));

        $this->assertFalse($kolaka->offers(ServiceType::DineIn));
        $this->assertFalse($kolaka->usesTables());
        $this->assertSame(KitchenMode::Ticket, $kolaka->kitchen_mode);
        $this->assertFalse($kolaka->tax_enabled);
    }

    public function test_staff_only_access_assigned_outlets(): void
    {
        $this->seed(DatabaseSeeder::class);

        $kasir = User::where('email', 'pabalu.kasir@gmail.com')->sole();
        $owner = User::where('email', 'pabalu.owner@gmail.com')->sole();

        $this->assertSame(['KDI'], $kasir->accessibleOutlets()->pluck('code')->all());
        $this->assertCount(3, $owner->accessibleOutlets());
        $this->assertFalse($kasir->canAccessOutlet(Outlet::where('code', 'KLK')->sole()));
    }

    public function test_seeded_accounts_can_log_in(): void
    {
        $this->seed(DatabaseSeeder::class);

        Volt::test('pages.auth.login')
            ->set('form.email', 'pabalu.kasir@gmail.com')
            ->set('form.password', UserSeeder::DEFAULT_PASSWORD)
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs(User::where('email', 'pabalu.kasir@gmail.com')->sole());
    }

    public function test_dashboard_shows_user_name_role_and_business(): void
    {
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('email', 'pabalu.owner@gmail.com')->sole();

        $this->actingAs($owner)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Ahmad Fulan')
            ->assertSee(UserRole::Owner->label())
            ->assertSee('Pabalu Coffee &amp; Kitchen', false)
            ->assertSee('Menu Terlaris');
    }
}
