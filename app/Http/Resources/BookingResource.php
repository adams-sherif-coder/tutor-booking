<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'booking_id' => $this->id,
            'student' => UserResource::make($this->whenLoaded('student')),
            'tutor' => TutorResource::make($this->whenLoaded('tutor')),
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'status' => $this->status,
            'hourly_rate' => $this->hourly_rate,
            'total_price' => $this->total_price,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
