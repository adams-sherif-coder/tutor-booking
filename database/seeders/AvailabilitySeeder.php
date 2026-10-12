<?php

namespace Database\Seeders;

use App\Models\Availability;
use App\Models\User;
use Illuminate\Database\Seeder;

class AvailabilitySeeder extends Seeder
{
    public function run(): void
    {
        foreach (User::where('role', 'tutor')->get() as $tutor) {
            for ($day = 2; $day <= 8; $day++) {
                Availability::factory()->create(['tutor_id' => $tutor->id, 'starts_at' => now()->addDays($day)->setTime(9, 0), 'ends_at' => now()->addDays($day)->setTime(17, 0)]);
            }
        }
    }
}
