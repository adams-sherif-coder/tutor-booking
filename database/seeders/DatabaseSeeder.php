<?php

namespace Database\Seeders;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SubjectSeeder::class);
        User::factory()->admin()->create(['name' => 'Demo Admin', 'email' => 'admin@example.com']);
        $tutors = User::factory()->tutor()->count(3)->create();
        $tutors->push(User::factory()->tutor()->create(['name' => 'Demo Tutor', 'email' => 'tutor@example.com', 'hourly_rate' => '50.00']));
        foreach ($tutors as $tutor) {
            $tutor->subjects()->attach(Subject::inRandomOrder()->limit(2)->pluck('id'));
        }
        User::factory()->count(4)->create();
        User::factory()->create(['name' => 'Demo Student', 'email' => 'student@example.com']);
        $this->call([AvailabilitySeeder::class, BookingSeeder::class]);
    }
}
