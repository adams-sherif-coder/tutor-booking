<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListTutorsRequest;
use App\Http\Requests\UpdateTutorRequest;
use App\Http\Resources\TutorResource;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\ScheduleLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TutorController extends Controller
{
    public function index(ListTutorsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tutors = User::where('role', 'tutor')->where('is_active', true)->with('subjects')
            ->when(isset($data['name']), fn ($query) => $query->where('name', 'like', '%'.$data['name'].'%'))
            ->when(isset($data['subject_id']), fn ($query) => $query->whereHas('subjects', fn ($subjects) => $subjects->where('subjects.id', $data['subject_id'])))
            ->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 15);

        return $this->json_response(['tutors' => TutorResource::collection($tutors), 'pagination' => $this->pagination($tutors)]);
    }

    public function show(User $tutor, AvailabilityService $availability): JsonResponse
    {
        $this->checkTutor($tutor);

        return $this->json_response(['tutor' => new TutorResource($tutor->load('subjects')), 'availability' => $availability->bookable($tutor)]);
    }

    public function profile(Request $request): JsonResponse
    {
        Gate::authorize('updateProfile', $request->user());

        return $this->json_response(['tutor' => new TutorResource($request->user()->load('subjects'))]);
    }

    public function update(UpdateTutorRequest $request, ScheduleLock $locks): JsonResponse
    {
        $tutor = DB::transaction(function () use ($request, $locks): User {
            $tutor = $locks->users($request->user()->id)[$request->user()->id];
            Gate::forUser($tutor)->authorize('updateProfile', $tutor);
            $data = $request->validated();
            $tutor->update(collect($data)->only(['name', 'bio', 'hourly_rate'])->all());
            $tutor->subjects()->sync($data['subject_ids']);

            return $tutor->load('subjects');
        }, 5);

        return $this->json_response(['tutor' => new TutorResource($tutor)], 'Profile updated.');
    }

    public function availability(ListTutorsRequest $request, User $tutor, AvailabilityService $availability): JsonResponse
    {
        $this->checkTutor($tutor);

        return $this->json_response(['availability' => $availability->bookable($tutor, $request->validated('date')), 'timezone' => 'UTC']);
    }

    private function checkTutor(User $tutor): void
    {
        abort_unless($tutor->role === 'tutor' && $tutor->is_active, 404, 'Tutor not found.');
    }
}
