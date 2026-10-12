<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTutorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateProfile', $this->user());
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'bio' => 'required|string|max:5000',
            'hourly_rate' => 'required|numeric|decimal:0,2|min:0.01|max:999999.99',
            'subject_ids' => 'required|array|min:1|max:30',
            'subject_ids.*' => 'required|integer|distinct|exists:subjects,id',
            'role' => 'prohibited',
            'is_active' => 'prohibited',
        ];
    }
}
