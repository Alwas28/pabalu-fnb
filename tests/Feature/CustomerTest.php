<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Pos\ShiftManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->business = Business::where('name', 'Pabalu Coffee & Kitchen')->sole();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->sole();
    }

    public function test_manager_manages_customers_with_unique_normalized_phone(): void
    {
        $this->actingAs($this->user('pabalu.manager@gmail.com'));

        Volt::test('pages.customers.index')
            ->call('create')->set('name', 'Andi')->set('phone', '+62 812-3456-789')->call('save')->assertHasNoErrors();

        $this->assertSame('08123456789', Customer::sole()->phone);

        Volt::test('pages.customers.index')
            ->call('create')->set('name', 'Andi Lain')->set('phone', '0812 3456 789')->call('save')->assertHasErrors(['phone' => 'unique']);

        // Pencarian nama tidak menampilkan pelanggan lain.
        $this->business->customers()->create(['name' => 'Budi']);
        Volt::test('pages.customers.index')->set('search', 'And')->assertSee('Andi')->assertDontSee('Budi');

        $this->actingAs($this->user('pabalu.kasir@gmail.com'))->get(route('customers.index'))->assertForbidden();
    }

    public function test_kasir_picks_or_creates_customer_at_pos_and_history_is_linked(): void
    {
        $kasir = $this->user('pabalu.kasir@gmail.com');
        app(ShiftManager::class)->open($kasir, Outlet::where('code', 'KDI')->sole(), ['opening_cash' => 0]);
        $this->actingAs($kasir);

        Volt::test('pages.pos.index')
            ->set('customerName', 'Citra')
            ->set('newCustomerPhone', '0813 1111 2222')
            ->call('createCustomer')
            ->assertHasNoErrors()
            ->call('pick', MenuItem::where('sku', 'NKP-003')->value('id'))
            ->call('openPayment')
            ->call('checkout')
            ->assertHasNoErrors();

        $customer = Customer::where('phone', '081311112222')->sole();
        $order = Order::sole();
        $this->assertTrue($order->customer->is($customer));
        $this->assertSame('Citra', $order->customer_name);

        Volt::test('pages.pos.index')->set('customerName', 'Cit')->assertSee('Citra')->call('selectCustomer', $customer->id)->assertSet('customerId', $customer->id);
    }

    public function test_customer_api_search_and_create(): void
    {
        Sanctum::actingAs($this->user('pabalu.kasir@gmail.com'), ['pos']);
        $this->business->customers()->create(['name' => 'Dewi', 'phone' => '081277778888']);

        $this->getJson('/api/v1/customers?search=7777')->assertOk()->assertJsonPath('data.0.name', 'Dewi');
        $this->postJson('/api/v1/customers', ['name' => 'Eko', 'phone' => '+62 812-7777-8888'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/customers', ['name' => 'Eko'])->assertCreated()->assertJsonPath('data.name', 'Eko');
    }
}
