<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListTutorsRequest;
use App\Http\Requests\StoreAvailabilityRequest;
use App\Http\Requests\UpdateAvailabilityRequest;
use App\Http\Resources\AvailabilityResource;
use App\Models\Availability;
use App\Services\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function index(ListTutorsRequest $request): JsonResponse
    {
        $page = $request->user()->availabilities()->where('ends_at', '>', now())->orderBy('starts_at')->paginate($request->validated('per_page', 15));

        return $this->json_response(['availability' => AvailabilityResource::collection($page), 'pagination' => $this->pagination($page)]);
    }

    public function store(StoreAvailabilityRequest $request, AvailabilityService $service): JsonResponse
    {
        return $this->json_response(['availability' => new AvailabilityResource($service->save($request->user(), $request->validated()))], 'Availability created.', 201);
    }

    public function update(UpdateAvailabilityRequest $request, Availability $availability, AvailabilityService $service): JsonResponse
    {
        return $this->json_response(['availability' => new AvailabilityResource($service->save($request->user(), $request->validated(), $availability))], 'Availability updated.');
    }

    public function destroy(Request $request, Availability $availability, AvailabilityService $service): JsonResponse
    {
        $service->delete($request->user(), $availability);

        return $this->json_response(message: 'Availability deleted.');
    }
}
