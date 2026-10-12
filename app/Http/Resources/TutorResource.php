<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TutorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'tutor_id' => $this->id,
            'name' => $this->name,
            'bio' => $this->bio,
            'hourly_rate' => $this->hourly_rate,
            'subjects' => SubjectResource::collection($this->whenLoaded('subjects')),
            'bookable_availability_url' => route('tutors.availability', ['tutor' => $this->id]),
        ];
    }
}
