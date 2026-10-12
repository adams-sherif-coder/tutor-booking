<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Database\Seeder;

class BookingSeeder extends Seeder
{
    public function run(): void
    {
        $tutor = User::where('email', 'tutor@example.com')->firstOrFail();
        $student = User::where('email', 'student@example.com')->firstOrFail();
        foreach (Booking::STATUSES as $index => $status) {
            $start = $status === 'completed' ? now()->subDays(2)->setTime(10, 0) : now()->addDays(3)->setTime(9 + $index, 0);
            Booking::factory()->create(['student_id' => $student->id, 'tutor_id' => $tutor->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addHour(), 'status' => $status, 'hourly_rate' => $tutor->hourly_rate, 'total_price' => $tutor->hourly_rate]);
        }
    }
}
