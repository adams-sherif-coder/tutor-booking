<?php

namespace App\Services;

use App\Models\Availability;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AvailabilityService
{
    public function __construct(private ScheduleLock $locks, private BookingTime $time) {}

    public function save(User $actor, array $data, ?Availability $availability = null): Availability
    {
        Gate::forUser($actor)->authorize($availability ? 'update' : 'create', $availability ?? Availability::class);

        return DB::transaction(function () use ($actor, $data, $availability): Availability {
            $actor = $this->locks->users($actor->id)[$actor->id];
            if ($availability) {
                $availability = Availability::whereKey($availability->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('update', $availability);
                $this->checkFuture($availability);
            } else {
                Gate::forUser($actor)->authorize('create', Availability::class);
            }
            [$start, $end] = $this->time->parse($data);
            $overlap = Availability::where('tutor_id', $actor->id)->when($availability, fn ($query) => $query->where('id', '!=', $availability->id))->where('starts_at', '<', $end)->where('ends_at', '>', $start)->lockForUpdate()->first();
            if ($overlap) {
                throw ValidationException::withMessages(['start_time' => 'Availability ranges must not overlap.']);
            }
            if ($availability) {
                $invalidated = Booking::active()->where('tutor_id', $actor->id)->where('starts_at', '<', $availability->ends_at)->where('ends_at', '>', $availability->starts_at)->where(function ($query) use ($start, $end): void {
                    $query->where('starts_at', '<', $start)->orWhere('ends_at', '>', $end);
                })->lockForUpdate()->first();
                if ($invalidated) {
                    throw ValidationException::withMessages(['availability' => 'This change would invalidate an active booking.']);
                }
            }
            $availability ??= new Availability;
            $availability->tutor_id = $actor->id;
            $availability->fill(['starts_at' => $start, 'ends_at' => $end])->save();

            return $availability;
        }, 5);
    }

    public function delete(User $actor, Availability $availability): void
    {
        Gate::forUser($actor)->authorize('delete', $availability);
        DB::transaction(function () use ($actor, $availability): void {
            $actor = $this->locks->users($actor->id)[$actor->id];
            $availability = Availability::whereKey($availability->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $availability);
            $this->checkFuture($availability);
            $booking = Booking::active()->where('tutor_id', $actor->id)->where('starts_at', '<', $availability->ends_at)->where('ends_at', '>', $availability->starts_at)->lockForUpdate()->first();
            if ($booking) {
                throw ValidationException::withMessages(['availability' => 'Availability containing an active booking cannot be deleted.']);
            }
            $availability->delete();
        }, 5);
    }

    public function bookable(User $tutor, ?string $date = null): array
    {
        if (! $tutor->is_active || $tutor->hourly_rate === null || ! $tutor->subjects()->exists()) {
            return [];
        }
        $ranges = $tutor->availabilities()->where('ends_at', '>', now())->when($date, fn ($query) => $query->whereDate('starts_at', $date))->orderBy('starts_at')->get();
        if ($ranges->isEmpty()) {
            return [];
        }
        $bookings = $tutor->tutorBookings()->active()->where('ends_at', '>', $ranges->first()->starts_at)->where('starts_at', '<', $ranges->last()->ends_at)->orderBy('starts_at')->get();
        $slots = [];
        $earliest = CarbonImmutable::now('UTC')->addMinute()->startOfMinute();
        foreach ($ranges as $range) {
            $cursor = $range->starts_at->max($earliest);
            foreach ($bookings as $booking) {
                if ($booking->ends_at->lte($cursor) || $booking->starts_at->gte($range->ends_at)) {
                    continue;
                }
                if ($booking->starts_at->gt($cursor)) {
                    $slots[] = $this->slot($cursor, $booking->starts_at);
                }
                $cursor = $cursor->max($booking->ends_at);
            }
            if ($cursor->lt($range->ends_at)) {
                $slots[] = $this->slot($cursor, $range->ends_at);
            }
        }

        return $slots;
    }

    private function slot(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return ['starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()];
    }

    private function checkFuture(Availability $availability): void
    {
        if ($availability->starts_at->lte(now())) {
            throw ValidationException::withMessages(['availability' => 'Only future availability can be changed.']);
        }
    }
}
