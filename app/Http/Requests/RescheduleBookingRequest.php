<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reschedule', $this->route('booking'));
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'student_id' => 'prohibited',
            'tutor_id' => 'prohibited',
            'status' => 'prohibited',
            'hourly_rate' => 'prohibited',
            'total_price' => 'prohibited',
        ];
    }
}
