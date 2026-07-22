<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHadithTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'term' => ['sometimes', 'required', 'string', 'max:255'],
            'explanation' => ['sometimes', 'required', 'string'],
        ];
    }
}
