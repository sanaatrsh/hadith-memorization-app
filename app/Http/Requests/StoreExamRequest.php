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
            // Optional: without it, the exam defaults to today's hadiths (or a
            // small slice of the book) rather than the whole book.
            'question_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
