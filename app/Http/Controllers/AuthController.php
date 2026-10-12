<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $data = $request->validated();
            $user = new User(collect($data)->only(['name', 'email', 'password'])->all());
            $user->role = $data['role'];
            $user->is_active = true;
            $user->save();

            return $this->json_response(['user' => new UserResource($user), 'token' => $user->createToken('api')->plainTextToken], 'Registered successfully.', 201);
        });
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->json_response(message: 'Invalid credentials.', status: 401, ok: false);
        }
        abort_unless($user->is_active, 403, 'Your account is inactive.');

        return $this->json_response(['user' => new UserResource($user), 'token' => $user->createToken('api')->plainTextToken]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->json_response(message: 'Logged out successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->json_response(['user' => new UserResource($request->user())]);
    }
}
