<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\BookingNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_login_and_current_user(): void
    {
        $this->postJson('/api/register', ['name' => 'Student', 'email' => 'new@example.com', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'student'])->assertCreated()->assertJsonPath('data.user.role', 'student')->assertJsonStructure(['data' => ['token']]);
        $token = $this->postJson('/api/login', ['email' => 'new@example.com', 'password' => 'password123'])->assertOk()->json('data.token');
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.user.email', 'new@example.com')->assertJsonMissingPath('data.user.password');
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');
        $this->withToken($token->plainTextToken)->postJson('/api/logout')->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_admin_registration_and_inactive_login_are_rejected(): void
    {
        $this->postJson('/api/register', ['name' => 'Admin', 'email' => 'bad@example.com', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'admin'])->assertUnprocessable();
        $user = User::factory()->inactive()->create();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertForbidden();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
    }

    public function test_unauthenticated_requests_to_protected_endpoints_are_rejected(): void
    {
        foreach (['/api/me', '/api/bookings', '/api/tutors', '/api/subjects', '/api/admin/users', '/api/tutor/availability'] as $url) {
            $this->getJson($url)->assertUnauthorized()->assertJsonPath('ok', false);
        }
        $this->postJson('/api/bookings')->assertUnauthorized();
    }

    public function test_tutor_profile_and_subject_search(): void
    {
        $tutor = User::factory()->tutor()->create(['name' => 'Jane Tutor']);
        $subject = Subject::factory()->create(['name' => 'Mathematics']);
        Sanctum::actingAs($tutor);
        $this->putJson('/api/tutor/profile', ['bio' => 'Math tutor', 'hourly_rate' => '60.50', 'subject_ids' => [$subject->id]])->assertOk()->assertJsonPath('data.tutor.hourly_rate', '60.50');
        $this->getJson('/api/tutors?name=Jane&subject_id='.$subject->id)->assertOk()->assertJsonCount(1, 'data.tutors')->assertJsonMissingPath('data.tutors.0.email');
        $this->getJson('/api/tutors?name=Missing')->assertOk()->assertJsonCount(0, 'data.tutors');
        $this->putJson('/api/tutor/profile', ['bio' => 'Tutor', 'hourly_rate' => 50, 'subject_ids' => [999]])->assertUnprocessable();
    }

    public function test_admin_filters_and_deactivation_revoke_tokens_and_block_access(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->create();
        $student->createToken('test');
        $booking = Booking::factory()->create(['student_id' => $student->id]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/users?role=student')->assertOk()->assertJsonCount(1, 'data.users');
        $this->getJson('/api/admin/bookings?student_id='.$student->id.'&tutor_id='.$booking->tutor_id.'&status=pending&date='.$booking->starts_at->toDateString())->assertOk()->assertJsonCount(1, 'data.bookings');
        $this->getJson('/api/bookings/'.$booking->id)->assertOk();
        $this->patchJson('/api/admin/users/'.$student->id.'/status', ['is_active' => false])->assertOk();
        $this->assertSame(0, $student->tokens()->count());
        Sanctum::actingAs($student->fresh());
        $this->getJson('/api/bookings')->assertForbidden();
        Sanctum::actingAs($admin);
        $this->patchJson('/api/admin/users/'.$student->id.'/status', ['is_active' => true])->assertOk();
        $this->patchJson('/api/admin/users/'.$admin->id.'/status', ['is_active' => false])->assertForbidden();
    }

    public function test_non_admin_cannot_manage_users_or_all_bookings(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->getJson('/api/admin/bookings')->assertForbidden();
        $this->patchJson('/api/admin/users/'.$student->id.'/status', ['is_active' => false])->assertForbidden();
    }

    public function test_notifications_are_dispatched_through_queue_after_commit(): void
    {
        Bus::fake();
        $booking = Booking::factory()->create();
        $booking->tutor->notify(new BookingNotification($booking, 'created'));
        Bus::assertDispatched(SendQueuedNotifications::class, fn ($job): bool => $job->notification->afterCommit === true && $job->notification->snapshot['booking_id'] === $booking->id);
    }

    public function test_notification_history_is_private(): void
    {
        $booking = Booking::factory()->create();
        $booking->tutor->notifyNow(new BookingNotification($booking, 'created'), ['database']);
        Sanctum::actingAs($booking->tutor);
        $id = $this->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data.notifications')->json('data.notifications.0.notification_id');
        $this->patchJson('/api/notifications/'.$id.'/read')->assertOk();
        Sanctum::actingAs($booking->student);
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data.notifications');
        $this->patchJson('/api/notifications/'.$id.'/read')->assertNotFound();
    }
}
