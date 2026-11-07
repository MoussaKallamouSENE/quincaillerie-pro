<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOwnProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'phone' => ['nullable', 'string', 'max:40'],
        ];
    }
}
