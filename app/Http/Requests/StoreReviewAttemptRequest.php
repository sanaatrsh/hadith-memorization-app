<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReviewAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_attempt_uuid' => ['required', 'uuid'],
            'recognized_text' => ['required', 'string', 'max:'.(int) config('athar.attempts.max_recognized_text_length', 10000)],
            'locale' => ['sometimes', 'in:ar'],
            'started_at' => ['nullable', 'date'],
        ];
    }
}
