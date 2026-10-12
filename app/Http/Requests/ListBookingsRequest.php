<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListBookingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => 'sometimes|in:upcoming,past',
            'status' => 'sometimes|in:pending,confirmed,completed,rejected,cancelled',
            'tutor_id' => 'sometimes|integer|exists:users,id',
            'student_id' => 'sometimes|integer|exists:users,id',
            'date' => 'sometimes|date_format:Y-m-d',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ];
    }
}
