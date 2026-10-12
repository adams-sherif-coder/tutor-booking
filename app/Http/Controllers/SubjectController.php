<?php

namespace App\Http\Controllers;

use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SubjectController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Subject::class);

        return $this->json_response(['subjects' => SubjectResource::collection(Subject::orderBy('name')->get())]);
    }
}
