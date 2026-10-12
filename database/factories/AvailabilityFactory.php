<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AvailabilityFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->addDays(3)->setTime(9, 0);

        return ['tutor_id' => User::factory()->tutor(), 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(8)];
    }
}
