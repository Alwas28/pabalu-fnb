<?php

namespace Tests\Feature;

use App\Enums\KitchenMode;
use App\Enums\PaymentGateway;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BusinessSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_admin_can_open_settings_but_others_cannot(): void
    {
        foreach ([UserRole::Owner, UserRole::BusinessAdmin] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get(route('settings.business'))
                ->assertOk()
                ->assertSee('Profil Bisnis');
        }

        foreach ([UserRole::ManagerOutlet, UserRole::Kasir, UserRole::KitchenStaff] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get(route('settings.business'))
                ->assertForbidden();
        }
    }

    public function test_only_owner_sees_payment_gateway_settings(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Owner)->create())
            ->get(route('settings.business'))->assertSee('Payment Gateway');

        $this->actingAs(User::factory()->role(UserRole::BusinessAdmin)->create())
            ->get(route('settings.business'))->assertDontSee('Payment Gateway');
    }

    public function test_owner_updates_outlet_settings(): void
    {
        $owner = User::factory()->role(UserRole::Owner)->create();
        $outlet = $owner->currentOutlet();
        $this->actingAs($owner);

        Volt::test('pages.settings.outlet-form', ['outlet' => $outlet])
            ->set('serviceTypes', ['takeaway', 'delivery'])
            ->set('tablesEnabled', true)
            ->set('kitchenMode', 'display')
            ->set('taxEnabled', true)
            ->set('taxRate', '11')
            ->set('serviceChargeEnabled', true)
            ->set('serviceChargeRate', '5')
            ->set('paymentMethods', ['cash', 'e_wallet'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('settings.business'));

        $outlet->refresh();
        $this->assertEquals([ServiceType::Takeaway, ServiceType::Delivery], $outlet->service_types->all());
        $this->assertSame(KitchenMode::Display, $outlet->kitchen_mode);
        $this->assertSame('11.00', $outlet->tax_rate);
        $this->assertSame('5.00', $outlet->service_charge_rate);
        $this->assertEquals([PaymentMethod::Cash, PaymentMethod::EWallet], $outlet->payment_methods->all());

        // Meja disimpan, tapi tidak berlaku karena dine-in nonaktif.
        $this->assertTrue($outlet->tables_enabled);
        $this->assertFalse($outlet->usesTables());
    }

    public function test_outlet_needs_at_least_one_service_and_payment_method(): void
    {
        $owner = User::factory()->role(UserRole::Owner)->create();
        $this->actingAs($owner);

        Volt::test('pages.settings.outlet-form', ['outlet' => $owner->currentOutlet()])
            ->set('serviceTypes', [])
            ->set('paymentMethods', [])
            ->set('taxEnabled', true)
            ->set('taxRate', '150')
            ->call('save')
            ->assertHasErrors(['serviceTypes', 'paymentMethods', 'taxRate']);
    }

    public function test_owner_creates_new_outlet(): void
    {
        $owner = User::factory()->role(UserRole::Owner)->create();
        $this->actingAs($owner);

        Volt::test('pages.settings.outlet-form')
            ->set('code', 'MKS')
            ->set('name', 'Outlet Makassar')
            ->set('serviceTypes', ['delivery'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('outlets', [
            'business_id' => $owner->activeBusiness()->id,
            'code' => 'MKS',
            'name' => 'Outlet Makassar',
        ]);
    }

    public function test_cannot_edit_outlet_of_another_business(): void
    {
        $owner = User::factory()->role(UserRole::Owner)->create();
        $foreignOutlet = User::factory()->role(UserRole::Owner)->create()->currentOutlet();

        $this->actingAs($owner)
            ->get(route('settings.outlets.edit', $foreignOutlet))
            ->assertForbidden();
    }

    public function test_payment_gateway_is_optional_and_credentials_are_encrypted(): void
    {
        $owner = User::factory()->role(UserRole::Owner)->create();
        $this->actingAs($owner);
        $business = $owner->activeBusiness();

        $this->assertFalse($business->paymentGatewayActive());

        // Mengaktifkan butuh provider & server key.
        Volt::test('pages.settings.business')
            ->set('gatewayEnabled', true)
            ->call('saveGateway')
            ->assertHasErrors(['gatewayProvider', 'gatewayServerKey']);

        Volt::test('pages.settings.business')
            ->set('gatewayEnabled', true)
            ->set('gatewayProvider', PaymentGateway::Midtrans->value)
            ->set('gatewayServerKey', 'SB-Mid-server-secret')
            ->call('saveGateway')
            ->assertHasNoErrors();

        $business->refresh();
        $this->assertTrue($business->paymentGatewayActive());
        $this->assertSame('SB-Mid-server-secret', $business->payment_gateway_credentials['server_key']);
        $this->assertStringNotContainsString(
            'SB-Mid-server-secret',
            (string) $business->getRawOriginal('payment_gateway_credentials'),
        );
    }

    public function test_admin_cannot_change_payment_gateway(): void
    {
        $this->actingAs(User::factory()->role(UserRole::BusinessAdmin)->create());

        Volt::test('pages.settings.business')
            ->set('gatewayEnabled', true)
            ->call('saveGateway')
            ->assertForbidden();
    }

    public function test_outlet_switcher_only_allows_assigned_outlets(): void
    {
        $kasir = User::factory()->role(UserRole::Kasir)->create();
        $business = $kasir->activeBusiness();
        $unassigned = Outlet::factory()->for($business)->create();
        $this->actingAs($kasir);

        Volt::test('layout.outlet-switcher')
            ->call('switchTo', $unassigned->id)
            ->assertForbidden();

        $owner = User::factory()->role(UserRole::Owner, $business)->create();
        $this->actingAs($owner);

        Volt::test('layout.outlet-switcher')->call('switchTo', $unassigned->id);
        $this->assertSame($unassigned->id, $owner->fresh()->current_outlet_id);
    }
}
