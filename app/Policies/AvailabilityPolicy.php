<?php

namespace App\Policies;

use App\Models\Availability;
use App\Models\User;

class AvailabilityPolicy
{
    public function create(User $user): bool
    {
        return $user->is_active && $user->role === 'tutor';
    }

    public function update(User $user, Availability $availability): bool
    {
        return $this->create($user) && $availability->tutor_id === $user->id;
    }

    public function delete(User $user, Availability $availability): bool
    {
        return $this->update($user, $availability);
    }
}
