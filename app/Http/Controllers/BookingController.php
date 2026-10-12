<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListBookingsRequest;
use App\Http\Requests\RescheduleBookingRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BookingController extends Controller
{
    public function index(ListBookingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $query = Booking::with(['student', 'tutor.subjects']);
        if ($user->role === 'admin') {
            Gate::authorize('viewAny', User::class);
            foreach (['tutor_id', 'student_id'] as $key) {
                if (isset($data[$key])) {
                    $query->where($key, $data[$key]);
                }
            }
        } else {
            $query->where($user->role === 'tutor' ? 'tutor_id' : 'student_id', $user->id);
        }
        if (isset($data['period'])) {
            $query->where('ends_at', $data['period'] === 'past' ? '<=' : '>', now());
            if ($data['period'] === 'upcoming') {
                $query->active();
            }
        }
        $page = $query->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(isset($data['date']), fn ($query) => $query->whereDate('starts_at', $data['date']))
            ->orderBy('starts_at', ($data['period'] ?? null) === 'past' ? 'desc' : 'asc')->orderBy('id')->paginate($data['per_page'] ?? 15);

        return $this->json_response(['bookings' => BookingResource::collection($page), 'pagination' => $this->pagination($page)]);
    }

    public function store(StoreBookingRequest $request, BookingService $service): JsonResponse
    {
        return $this->result($service->create($request->user(), $request->validated()), 'Booking created.', 201);
    }

    public function show(Booking $booking): JsonResponse
    {
        Gate::authorize('view', $booking);

        return $this->result($booking);
    }

    public function reschedule(RescheduleBookingRequest $request, Booking $booking, BookingService $service): JsonResponse
    {
        return $this->result($service->reschedule($request->user(), $booking, $request->validated()), 'Booking rescheduled; awaiting tutor approval.');
    }

    public function cancel(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        return $this->result($service->transition($request->user(), $booking, 'cancelled'), 'Booking cancelled.');
    }

    public function confirm(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        return $this->result($service->transition($request->user(), $booking, 'confirmed'), 'Booking confirmed.');
    }

    public function reject(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        return $this->result($service->transition($request->user(), $booking, 'rejected'), 'Booking rejected.');
    }

    public function complete(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        return $this->result($service->transition($request->user(), $booking, 'completed'), 'Booking completed.');
    }

    private function result(Booking $booking, ?string $message = null, int $status = 200): JsonResponse
    {
        return $this->json_response(['booking' => new BookingResource($booking->load(['student', 'tutor.subjects']))], $message, $status);
    }
}
