<?php

namespace Tests\Feature;

use App\Models\Availability;
use App\Models\Booking;
use App\Models\Subject;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueNotificationTest extends TestCase
{
    use DatabaseMigrations;

    private function createBooking(): Booking
    {
        $student = User::factory()->create();
        $tutor = User::factory()->tutor()->hasAttached(Subject::factory())->create();
        Availability::factory()->create(['tutor_id' => $tutor->id]);

        return app(BookingService::class)->create($student, ['tutor_id' => $tutor->id, 'date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00']);
    }

    public function test_database_queue_delivers_booking_notification(): void
    {
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        $booking = $this->createBooking();
        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseCount('notifications', 0);
        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $booking->tutor_id, 'notifiable_type' => User::class]);
        $this->assertSame('created', $booking->tutor->notifications()->first()->data['event']);
    }

    public function test_rolled_back_booking_does_not_enqueue_notifications(): void
    {
        config(['queue.default' => 'database']);
        DB::beginTransaction();
        $this->createBooking();
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('jobs', 0);
    }
}
