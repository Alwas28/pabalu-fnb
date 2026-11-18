<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Jadikan user anggota sebuah bisnis dengan role tertentu.
     *
     * Bila $business tidak diberikan, dibuat bisnis baru (berikut satu outlet).
     * Role non-owner/admin otomatis ditugaskan ke outlet pertama bisnis tersebut.
     */
    public function role(UserRole $role, ?Business $business = null): static
    {
        return $this->afterCreating(function (User $user) use ($role, $business) {
            $business ??= Business::factory()
                ->has(Outlet::factory())
                ->create(['owner_id' => $role === UserRole::Owner ? $user->id : User::factory()]);

            $membership = BusinessUser::create([
                'business_id' => $business->id,
                'user_id' => $user->id,
                'role' => $role,
            ]);

            if (! $role->coversAllOutlets()) {
                $membership->outlets()->attach($business->outlets()->value('id'));
            }

            $user->forceFill(['current_business_id' => $business->id])->save();
            $user->forgetMembership();
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
