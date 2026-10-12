<?php

namespace App\Services;

use App\Models\Availability;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\BookingNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(private ScheduleLock $locks, private BookingTime $time) {}

    public function create(User $actor, array $data): Booking
    {
        Gate::forUser($actor)->authorize('create', Booking::class);

        return DB::transaction(function () use ($actor, $data): Booking {
            $users = $this->locks->users($actor->id, (int) $data['tutor_id']);
            $student = $users[$actor->id];
            $tutor = $users[(int) $data['tutor_id']];
            $this->checkParticipants($student, $tutor);
            [$start, $end] = $this->time->parse($data);
            $this->checkSlot($student->id, $tutor->id, $start, $end);
            $booking = new Booking(['starts_at' => $start, 'ends_at' => $end]);
            $booking->student_id = $student->id;
            $booking->tutor_id = $tutor->id;
            $booking->status = 'pending';
            $booking->hourly_rate = $tutor->hourly_rate;
            $booking->total_price = $this->price($tutor->hourly_rate, $start, $end);
            $booking->save();
            $tutor->notify(new BookingNotification($booking, 'created'));

            return $booking;
        }, 5);
    }

    public function reschedule(User $actor, Booking $booking, array $data): Booking
    {
        Gate::forUser($actor)->authorize('reschedule', $booking);

        return DB::transaction(function () use ($actor, $booking, $data): Booking {
            $users = $this->locks->users($booking->student_id, $booking->tutor_id);
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($users[$actor->id])->authorize('reschedule', $booking);
            $this->checkParticipants($users[$booking->student_id], $users[$booking->tutor_id]);
            $this->checkCutoff($booking);
            [$start, $end] = $this->time->parse($data);
            $this->checkSlot($booking->student_id, $booking->tutor_id, $start, $end, $booking->id);
            $booking->starts_at = $start;
            $booking->ends_at = $end;
            $booking->total_price = $this->price($booking->hourly_rate, $start, $end);
            $booking->status = 'pending';
            $booking->save();
            foreach ($users as $user) {
                $user->notify(new BookingNotification($booking, 'rescheduled'));
            }

            return $booking;
        }, 5);
    }

    public function transition(User $actor, Booking $booking, string $status): Booking
    {
        $ability = $status === 'cancelled' ? 'cancel' : 'manage';
        Gate::forUser($actor)->authorize($ability, $booking);

        return DB::transaction(function () use ($actor, $booking, $status, $ability): Booking {
            $users = $this->locks->users($booking->student_id, $booking->tutor_id);
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($users[$actor->id])->authorize($ability, $booking);
            $previous = $booking->status;
            $valid = match ($status) {
                'confirmed', 'rejected' => $previous === 'pending' && $booking->starts_at->isFuture(),
                'completed' => $previous === 'confirmed' && $booking->ends_at->lte(now()),
                'cancelled' => in_array($previous, Booking::ACTIVE_STATUSES, true),
                default => false,
            };
            if (! $valid) {
                throw ValidationException::withMessages(['status' => 'This booking status transition is not allowed.']);
            }
            if ($status === 'cancelled') {
                $this->checkCutoff($booking);
            }
            if ($status === 'confirmed') {
                $this->checkParticipants($users[$booking->student_id], $users[$booking->tutor_id]);
            }
            $booking->status = $status;
            $booking->save();
            if (in_array($status, ['confirmed', 'rejected'], true)) {
                $users[$booking->student_id]->notify(new BookingNotification($booking, $status));
            } elseif ($status === 'cancelled') {
                foreach ($users as $user) {
                    $user->notify(new BookingNotification($booking, $status));
                }
            }

            return $booking;
        }, 5);
    }

    private function checkParticipants(User $student, User $tutor): void
    {
        if (! $student->is_active || $student->role !== 'student' || ! $tutor->is_active || $tutor->role !== 'tutor' || $tutor->hourly_rate === null || ! $tutor->subjects()->exists()) {
            throw ValidationException::withMessages(['tutor_id' => 'Choose an active tutor with a complete profile. Both participants must be active.']);
        }
    }

    private function checkSlot(int $studentId, int $tutorId, CarbonImmutable $start, CarbonImmutable $end, ?int $except = null): void
    {
        $availability = Availability::where('tutor_id', $tutorId)->where('starts_at', '<=', $start)->where('ends_at', '>=', $end)->lockForUpdate()->first();
        if (! $availability) {
            throw ValidationException::withMessages(['start_time' => 'The session must fit completely inside one tutor availability range.']);
        }
        $overlap = Booking::active()->where(function ($query) use ($studentId, $tutorId): void {
            $query->where('tutor_id', $tutorId)->orWhere('student_id', $studentId);
        })->when($except, fn ($query) => $query->where('id', '!=', $except))
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->lockForUpdate()->first();
        if ($overlap) {
            throw ValidationException::withMessages(['start_time' => 'The tutor or student already has an overlapping booking.']);
        }
    }

    private function checkCutoff(Booking $booking): void
    {
        if (! in_array($booking->status, Booking::ACTIVE_STATUSES, true) || $booking->starts_at->lte(now()) || $booking->starts_at->lt(now()->addHours(config('booking.cancellation_cutoff_hours')))) {
            throw ValidationException::withMessages(['booking' => 'Only active future bookings outside the cancellation/rescheduling cutoff can be changed.']);
        }
    }

    private function price(string $rate, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $cents = (int) str_replace('.', '', $rate);
        $minutes = (int) $start->diffInMinutes($end);
        $totalCents = intdiv($cents * $minutes + 30, 60);

        return sprintf('%d.%02d', intdiv($totalCents, 100), $totalCents % 100);
    }
}
