<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        return $user->is_active && ($user->role === 'admin' || $booking->student_id === $user->id || $booking->tutor_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->role === 'student';
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $user->is_active && (($user->role === 'student' && $booking->student_id === $user->id) || ($user->role === 'tutor' && $booking->tutor_id === $user->id));
    }

    public function reschedule(User $user, Booking $booking): bool
    {
        return $user->is_active && $user->role === 'student' && $booking->student_id === $user->id;
    }

    public function manage(User $user, Booking $booking): bool
    {
        return $user->is_active && $user->role === 'tutor' && $booking->tutor_id === $user->id;
    }
}
