<?php

namespace Tests\Feature;

use App\Enums\KitchenMode;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_business_is_redirected_to_onboarding(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertRedirect(route('onboarding'));
    }

    public function test_onboarding_creates_business_owner_membership_and_first_outlet(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('pages.onboarding')
            ->set('businessName', 'Kopi Senja')
            ->set('outletName', 'Outlet Kendari')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $user->refresh()->forgetMembership();

        $this->assertSame('Kopi Senja', $user->activeBusiness()->name);
        $this->assertSame(UserRole::Owner, $user->currentRole());
        $this->assertSame($user->id, $user->activeBusiness()->owner_id);

        $outlet = $user->currentOutlet();
        $this->assertSame('Outlet Kendari', $outlet->name);
        // Pengaturan default outlet baru
        $this->assertSame(['dine_in', 'takeaway'], $outlet->service_types->map->value->all());
        $this->assertSame(KitchenMode::Ticket, $outlet->kitchen_mode);
        $this->assertFalse($outlet->tables_enabled);

        $this->get('/dashboard')->assertOk();
    }

    public function test_onboarding_requires_names(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('pages.onboarding')
            ->call('save')
            ->assertHasErrors(['businessName' => 'required', 'outletName' => 'required']);
    }

    public function test_members_skip_onboarding(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Kasir)->create());

        Volt::test('pages.onboarding')->assertRedirect(route('dashboard'));
    }
}
