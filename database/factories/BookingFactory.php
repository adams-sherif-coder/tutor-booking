<?php

namespace Database\Factories;

use App\Models\Availability;
use App\Models\Booking;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookingFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->addDays(3)->setTime(10, 0);

        return ['tutor_id' => User::factory()->tutor(), 'student_id' => User::factory(), 'starts_at' => $start, 'ends_at' => $start->copy()->addHour(), 'status' => 'pending', 'hourly_rate' => '50.00', 'total_price' => '50.00'];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Booking $booking): void {
            if (! $booking->tutor->subjects()->exists()) {
                $booking->tutor->subjects()->attach(Subject::factory()->create());
            }
            if (! $booking->tutor->availabilities()->where('starts_at', '<=', $booking->starts_at)->where('ends_at', '>=', $booking->ends_at)->exists()) {
                Availability::factory()->create(['tutor_id' => $booking->tutor_id, 'starts_at' => $booking->starts_at, 'ends_at' => $booking->ends_at]);
            }
        });
    }
}
