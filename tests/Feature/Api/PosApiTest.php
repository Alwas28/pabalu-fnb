<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->sole();
    }

    public function test_kasir_can_login_and_receives_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'pabalu.kasir@gmail.com',
            'password' => 'password',
            'device_name' => 'Tablet Kasir 1',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'token_type', 'data' => ['user', 'business', 'role', 'permissions', 'outlets']])
            ->assertJsonPath('data.role.code', UserRole::Kasir->value)
            ->assertJsonPath('data.outlets.0.code', 'KDI')
            ->assertJsonCount(1, 'data.outlets');

        $this->assertSame(['pos'], $this->user('pabalu.kasir@gmail.com')->tokens()->sole()->abilities);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'pabalu.kasir@gmail.com',
            'password' => 'salah',
            'device_name' => 'Tablet',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_roles_without_pos_access_cannot_login(): void
    {
        foreach (['pabalu.dapur@gmail.com', 'pabalu.gudang@gmail.com', 'pabalu.admin@gmail.com'] as $email) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $email,
                'password' => 'password',
                'device_name' => 'Tablet',
            ])->assertForbidden();
        }
    }

    public function test_outlet_config_reflects_outlet_settings(): void
    {
        Sanctum::actingAs($this->user('pabalu.kasir@gmail.com'), ['pos']);
        $kendari = Outlet::where('code', 'KDI')->sole();

        $this->getJson("/api/v1/outlets/{$kendari->id}")
            ->assertOk()
            ->assertJsonPath('data.config.tables_enabled', true)
            ->assertJsonPath('data.config.kitchen.mode', 'display')
            ->assertJsonPath('data.config.tax.enabled', true)
            ->assertJsonPath('data.config.tax.rate', 10)
            ->assertJsonPath('data.config.service_charge.rate', 5)
            ->assertJsonPath('data.config.payment_gateway.enabled', false)
            ->assertJsonPath('data.config.payment_methods.0.via_gateway', false)
            ->assertJsonCount(3, 'data.config.service_types');
    }

    public function test_kasir_cannot_access_unassigned_outlet(): void
    {
        Sanctum::actingAs($this->user('pabalu.kasir@gmail.com'), ['pos']);

        $this->getJson('/api/v1/outlets/'.Outlet::where('code', 'KLK')->sole()->id)
            ->assertForbidden();
    }

    public function test_supervisor_lists_only_assigned_outlets(): void
    {
        Sanctum::actingAs($this->user('pabalu.supervisor@gmail.com'), ['pos']);

        $this->getJson('/api/v1/outlets')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_token_without_pos_ability_is_rejected(): void
    {
        Sanctum::actingAs($this->user('pabalu.kasir@gmail.com'), ['other']);

        $this->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_requests_without_token_are_unauthorized(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_logout_revokes_current_token(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'pabalu.kasir@gmail.com',
            'password' => 'password',
            'device_name' => 'Tablet',
        ])->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame(0, $this->user('pabalu.kasir@gmail.com')->tokens()->count());
    }
}
