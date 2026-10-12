<?php

namespace App\Policies;

use App\Models\User;

class SubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }
}
