<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role === 'admin';
    }

    public function updateStatus(User $user, User $target): bool
    {
        return $this->viewAny($user) && in_array($target->role, ['student', 'tutor'], true);
    }

    public function updateProfile(User $user, User $target): bool
    {
        return $user->is_active && $user->role === 'tutor' && $user->id === $target->id;
    }
}
