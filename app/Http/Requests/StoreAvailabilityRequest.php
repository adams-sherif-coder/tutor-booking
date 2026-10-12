<?php

namespace App\Http\Requests;

use App\Models\Availability;
use Illuminate\Foundation\Http\FormRequest;

class StoreAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Availability::class);
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'tutor_id' => 'prohibited',
        ];
    }
}
