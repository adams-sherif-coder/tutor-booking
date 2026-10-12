<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListUsersRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ScheduleLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AdminController extends Controller
{
    public function users(ListUsersRequest $request): JsonResponse
    {
        $data = $request->validated();
        $page = User::whereIn('role', ['student', 'tutor'])->when(isset($data['role']), fn ($query) => $query->where('role', $data['role']))
            ->when(isset($data['is_active']), fn ($query) => $query->where('is_active', $data['is_active']))->orderBy('id')->paginate($data['per_page'] ?? 15);

        return $this->json_response(['users' => UserResource::collection($page), 'pagination' => $this->pagination($page)]);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user, ScheduleLock $locks): JsonResponse
    {
        $user = DB::transaction(function () use ($request, $user, $locks): User {
            $user = $locks->users($user->id)[$user->id];
            Gate::authorize('updateStatus', $user);
            $user->is_active = $request->boolean('is_active');
            $user->save();
            if (! $user->is_active) {
                $user->tokens()->delete();
            }

            return $user;
        }, 5);

        return $this->json_response(['user' => new UserResource($user)], 'User status updated.');
    }
}
