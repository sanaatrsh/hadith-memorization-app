<?php

namespace App\Http\Requests\Admin;

use App\Enums\MemorizationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(MemorizationStatus::class)],
            'srs_level' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'next_review_at' => ['sometimes', 'nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
