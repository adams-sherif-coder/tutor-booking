<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListBookingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(ListBookingsRequest $request): JsonResponse
    {
        $page = $request->user()->notifications()->paginate($request->validated('per_page', 15));

        return $this->json_response(['notifications' => $page->getCollection()->map(fn ($notification): array => ['notification_id' => $notification->id, 'data' => $notification->data, 'read_at' => $notification->read_at, 'created_at' => $notification->created_at]), 'pagination' => $this->pagination($page)]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return $this->json_response(message: 'Notification marked as read.');
    }
}
