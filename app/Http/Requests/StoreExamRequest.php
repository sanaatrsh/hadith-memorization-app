<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'book_id' => ['required', 'integer', 'exists:books,id'],
            // Optional: the exam covers every hadith in the book by default.
            'question_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
