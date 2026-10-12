<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return ['name' => fake()->name(), 'email' => fake()->unique()->safeEmail(), 'email_verified_at' => now(), 'password' => static::$password ??= Hash::make('password'), 'remember_token' => Str::random(10), 'role' => 'student', 'is_active' => true];
    }

    public function tutor(): static
    {
        return $this->state(fn (): array => ['role' => 'tutor', 'bio' => fake()->paragraph(), 'hourly_rate' => fake()->numberBetween(20, 100)]);
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => 'admin']);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }
}
