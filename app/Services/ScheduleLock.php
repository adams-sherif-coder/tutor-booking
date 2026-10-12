<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

class ScheduleLock
{
    /** Lock stable parent rows, including when no booking rows exist yet. Call inside a transaction. */
    public function users(int ...$ids): Collection
    {
        $ids = array_unique($ids);
        sort($ids, SORT_NUMERIC);
        $users = collect();
        foreach ($ids as $id) {
            $users->put($id, User::whereKey($id)->lockForUpdate()->firstOrFail());
        }

        return $users;
    }
}
