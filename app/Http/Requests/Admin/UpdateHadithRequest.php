<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHadithRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'book_id' => ['sometimes', 'required', 'integer', 'exists:books,id'],
            'narrator_id' => ['nullable', 'integer', 'exists:narrators,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'text' => ['sometimes', 'required', 'string'],
            // مقدمة الحديث: the isnad/context line it opens with.
            'intro' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            // The hadith's number inside its book. Left out, it continues
            // the book's numbering; it must stay unique within the book.
            'number_in_book' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
