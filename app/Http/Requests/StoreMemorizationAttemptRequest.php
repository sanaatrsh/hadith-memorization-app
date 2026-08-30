<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemorizationAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_attempt_uuid' => ['required', 'uuid'],
            'hadith_id' => ['required', 'integer', 'exists:hadiths,id'],
            'recognized_text' => ['required', 'string', 'max:'.(int) config('athar.attempts.max_recognized_text_length', 10000)],
            // Ties the recitation to a review sitting so the session can
            // report its result. Optional: reciting outside a session is fine.
            'review_session_id' => ['nullable', 'integer', 'exists:review_sessions,id'],
            'locale' => ['sometimes', 'in:ar'],
            'started_at' => ['nullable', 'date'],
        ];
    }
}
