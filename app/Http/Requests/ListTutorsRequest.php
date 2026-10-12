<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListTutorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'subject_id' => 'sometimes|integer|exists:subjects,id',
            'date' => 'sometimes|date_format:Y-m-d',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ];
    }
}
