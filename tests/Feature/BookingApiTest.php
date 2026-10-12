<?php

namespace Tests\Feature;

use App\Models\Availability;
use App\Models\Booking;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\BookingNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $tutor;

    private Availability $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        Notification::fake();
        $this->student = User::factory()->create();
        $this->tutor = User::factory()->tutor()->hasAttached(Subject::factory())->create(['hourly_rate' => '50.00']);
        $this->availability = Availability::factory()->create(['tutor_id' => $this->tutor->id]);
        Sanctum::actingAs($this->student);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['tutor_id' => $this->tutor->id, 'date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00'], $overrides);
    }

    private function book(array $overrides = []): Booking
    {
        $id = $this->postJson('/api/bookings', $this->data($overrides))->assertCreated()->json('data.booking.booking_id');

        return Booking::findOrFail($id);
    }

    public function test_student_can_book_available_time_with_server_calculated_price(): void
    {
        $booking = $this->book(['end_time' => '11:30']);
        $this->assertSame('pending', $booking->status);
        $this->assertSame('75.00', $booking->total_price);
        $this->assertSame($this->student->id, $booking->student_id);
        Notification::assertSentTo($this->tutor, BookingNotification::class, fn ($notification): bool => $notification->event === 'created' && $notification->afterCommit === true);
    }

    public function test_booking_outside_availability_is_rejected(): void
    {
        $this->postJson('/api/bookings', $this->data(['start_time' => '08:00', 'end_time' => '10:00']))->assertUnprocessable()->assertJsonValidationErrors('start_time');
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_booking_in_past_is_rejected(): void
    {
        $this->postJson('/api/bookings', $this->data(['date' => now()->subDay()->toDateString()]))->assertUnprocessable();
    }

    public function test_end_must_be_after_start(): void
    {
        foreach (['09:00', '10:00'] as $end) {
            $this->postJson('/api/bookings', $this->data(['end_time' => $end]))->assertUnprocessable()->assertJsonValidationErrors('end_time');
        }
    }

    public function test_overlapping_tutor_bookings_are_rejected(): void
    {
        $this->book();
        Sanctum::actingAs(User::factory()->create());
        foreach ([['10:00', '11:00'], ['09:30', '10:30'], ['10:30', '11:30'], ['09:30', '11:30'], ['10:15', '10:45']] as [$start, $end]) {
            $this->postJson('/api/bookings', $this->data(['start_time' => $start, 'end_time' => $end]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_overlapping_student_bookings_with_different_tutors_are_rejected(): void
    {
        $this->book();
        $other = User::factory()->tutor()->hasAttached(Subject::factory())->create();
        Availability::factory()->create(['tutor_id' => $other->id]);
        $this->postJson('/api/bookings', $this->data(['tutor_id' => $other->id]))->assertUnprocessable();
    }

    public function test_adjacent_bookings_are_allowed(): void
    {
        $this->book();
        $this->book(['start_time' => '11:00', 'end_time' => '12:00']);
        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_other_student_cannot_read_cancel_or_reschedule_booking(): void
    {
        $booking = $this->book();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/bookings/'.$booking->id)->assertForbidden();
        $this->patchJson('/api/bookings/'.$booking->id.'/cancel')->assertForbidden();
        $this->patchJson('/api/bookings/'.$booking->id.'/reschedule', collect($this->data())->except('tutor_id')->all())->assertForbidden();
        $this->getJson('/api/bookings?student_id='.$this->student->id)->assertOk()->assertJsonCount(0, 'data.bookings');
    }

    public function test_unrelated_tutor_cannot_read_or_manage_booking(): void
    {
        $booking = $this->book();
        Sanctum::actingAs(User::factory()->tutor()->create());
        $this->getJson('/api/bookings/'.$booking->id)->assertForbidden();
        foreach (['confirm', 'reject', 'complete', 'cancel'] as $action) {
            $this->patchJson('/api/bookings/'.$booking->id.'/'.$action)->assertForbidden();
        }
    }

    public function test_students_cannot_modify_availability(): void
    {
        $this->postJson('/api/tutor/availability', collect($this->data())->except('tutor_id')->all())->assertForbidden();
        $this->deleteJson('/api/tutor/availability/'.$this->availability->id)->assertForbidden();
    }

    public function test_tutor_cannot_modify_another_tutors_availability(): void
    {
        Sanctum::actingAs(User::factory()->tutor()->create());
        $this->putJson('/api/tutor/availability/'.$this->availability->id, collect($this->data())->except('tutor_id')->all())->assertForbidden();
        $this->deleteJson('/api/tutor/availability/'.$this->availability->id)->assertForbidden();
    }

    public function test_booked_time_is_subtracted_and_cancellation_releases_it(): void
    {
        $booking = $this->book();
        $url = '/api/tutors/'.$this->tutor->id.'/availability';
        $slots = $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.availability')->json('data.availability');
        $this->assertSame($booking->starts_at->toIso8601String(), $slots[0]['ends_at']);
        $this->assertSame($booking->ends_at->toIso8601String(), $slots[1]['starts_at']);
        $this->patchJson('/api/bookings/'.$booking->id.'/cancel')->assertOk();
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled']);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.availability');
        $this->book();
    }

    public function test_invalid_status_transitions_and_premature_completion_are_rejected(): void
    {
        $booking = $this->book();
        $url = '/api/bookings/'.$booking->id;
        $this->patchJson($url.'/confirm')->assertForbidden();
        Sanctum::actingAs($this->tutor);
        $this->patchJson($url.'/complete')->assertUnprocessable();
        $this->patchJson($url.'/confirm')->assertOk()->assertJsonPath('data.booking.status', 'confirmed');
        $this->patchJson($url.'/confirm')->assertUnprocessable();
        $this->patchJson($url.'/reject')->assertUnprocessable();
        $this->patchJson($url.'/complete')->assertUnprocessable();
        $this->travelTo($booking->ends_at->addMinute());
        $this->patchJson($url.'/complete')->assertOk()->assertJsonPath('data.booking.status', 'completed');
        $this->patchJson($url.'/cancel')->assertUnprocessable();
    }

    public function test_rejection_releases_time_and_preserves_history(): void
    {
        $booking = $this->book();
        Sanctum::actingAs($this->tutor);
        $this->patchJson('/api/bookings/'.$booking->id.'/reject')->assertOk();
        Notification::assertSentTo($this->student, BookingNotification::class, fn ($n): bool => $n->event === 'rejected');
        Sanctum::actingAs($this->student);
        $this->book();
        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_rescheduling_rechecks_availability_and_overlaps_without_losing_original(): void
    {
        $booking = $this->book();
        $this->book(['start_time' => '12:00', 'end_time' => '13:00']);
        $url = '/api/bookings/'.$booking->id.'/reschedule';
        $base = collect($this->data())->except('tutor_id')->all();
        $this->patchJson($url, array_replace($base, ['start_time' => '08:00', 'end_time' => '09:00']))->assertUnprocessable();
        $this->patchJson($url, array_replace($base, ['start_time' => '12:00', 'end_time' => '13:00']))->assertUnprocessable();
        $this->assertSame('10:00', $booking->fresh()->starts_at->format('H:i'));
        Sanctum::actingAs($this->tutor);
        $this->patchJson('/api/bookings/'.$booking->id.'/confirm')->assertOk();
        Sanctum::actingAs($this->student);
        $this->patchJson($url, array_replace($base, ['start_time' => '14:00', 'end_time' => '15:30']))->assertOk()->assertJsonPath('data.booking.status', 'pending')->assertJsonPath('data.booking.total_price', '75.00');
        Notification::assertSentTo([$this->student, $this->tutor], BookingNotification::class, fn ($n): bool => $n->event === 'rescheduled');
    }

    public function test_rescheduling_checks_student_overlaps_with_other_tutors(): void
    {
        $booking = $this->book();
        Booking::factory()->create(['student_id' => $this->student->id, 'starts_at' => now()->addDays(3)->setTime(12, 0), 'ends_at' => now()->addDays(3)->setTime(13, 0)]);
        $this->patchJson('/api/bookings/'.$booking->id.'/reschedule', ['date' => now()->addDays(3)->toDateString(), 'start_time' => '12:00', 'end_time' => '13:00'])->assertUnprocessable();
    }

    public function test_cutoff_applies_to_student_tutor_and_rescheduling(): void
    {
        $booking = $this->book();
        $this->travelTo($booking->starts_at->subHours(23));
        $this->patchJson('/api/bookings/'.$booking->id.'/cancel')->assertUnprocessable();
        $this->patchJson('/api/bookings/'.$booking->id.'/reschedule', ['date' => now()->addDays(5)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00'])->assertUnprocessable();
        Sanctum::actingAs($this->tutor);
        $this->patchJson('/api/bookings/'.$booking->id.'/cancel')->assertUnprocessable();
    }

    public function test_past_and_completed_bookings_cannot_be_rescheduled(): void
    {
        $booking = $this->book();
        $this->travelTo($booking->ends_at->addDay());
        $this->patchJson('/api/bookings/'.$booking->id.'/reschedule', ['date' => now()->addDays(5)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00'])->assertUnprocessable();
        $booking->status = 'completed';
        $booking->save();
        $this->patchJson('/api/bookings/'.$booking->id.'/reschedule', ['date' => now()->addDays(5)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00'])->assertUnprocessable();
    }

    public function test_availability_changes_cannot_invalidate_active_bookings(): void
    {
        $this->book();
        Sanctum::actingAs($this->tutor);
        $url = '/api/tutor/availability/'.$this->availability->id;
        $this->deleteJson($url)->assertUnprocessable();
        $this->putJson($url, ['date' => now()->addDays(3)->toDateString(), 'start_time' => '12:00', 'end_time' => '17:00'])->assertUnprocessable();
        $this->putJson($url, ['date' => now()->addDays(3)->toDateString(), 'start_time' => '09:00', 'end_time' => '12:00'])->assertOk();
    }

    public function test_tutor_can_manage_future_availability_and_cannot_create_overlaps(): void
    {
        Sanctum::actingAs($this->tutor);
        $data = ['date' => now()->addDays(4)->toDateString(), 'start_time' => '09:00', 'end_time' => '12:00'];
        $id = $this->postJson('/api/tutor/availability', $data)->assertCreated()->json('data.availability.availability_id');
        $this->postJson('/api/tutor/availability', $data)->assertUnprocessable();
        $this->deleteJson('/api/tutor/availability/'.$id)->assertOk();
        $this->postJson('/api/tutor/availability', array_replace($data, ['date' => now()->subDay()->toDateString()]))->assertUnprocessable();
    }

    public function test_request_cannot_set_ownership_price_or_status(): void
    {
        foreach (['student_id' => 999, 'hourly_rate' => 1, 'total_price' => 1, 'status' => 'confirmed'] as $key => $value) {
            $this->postJson('/api/bookings', $this->data([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_inactive_or_incomplete_tutors_cannot_be_booked(): void
    {
        $this->tutor->is_active = false;
        $this->tutor->save();
        $this->postJson('/api/bookings', $this->data())->assertUnprocessable();
        $this->tutor->is_active = true;
        $this->tutor->hourly_rate = null;
        $this->tutor->save();
        $this->postJson('/api/bookings', $this->data())->assertUnprocessable();
    }

    public function test_booking_history_and_upcoming_lists_are_scoped(): void
    {
        $booking = $this->book();
        $this->getJson('/api/bookings?period=upcoming')->assertOk()->assertJsonCount(1, 'data.bookings');
        $this->travelTo($booking->ends_at->addDay());
        $this->getJson('/api/bookings?period=past')->assertOk()->assertJsonCount(1, 'data.bookings');
        $this->getJson('/api/bookings?period=upcoming')->assertOk()->assertJsonCount(0, 'data.bookings');
        Sanctum::actingAs($this->tutor);
        $this->getJson('/api/bookings?period=past')->assertOk()->assertJsonCount(1, 'data.bookings');
    }
}
