<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExamAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_question_id' => ['required', 'integer', 'exists:exam_questions,id'],
            'answer_text' => ['required', 'string', 'max:'.(int) config('athar.attempts.max_recognized_text_length', 10000)],
        ];
    }
}
