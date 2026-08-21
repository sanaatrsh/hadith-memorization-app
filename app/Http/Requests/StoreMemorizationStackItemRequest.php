<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemorizationStackItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hadith_id' => ['required', 'integer', 'exists:hadiths,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
