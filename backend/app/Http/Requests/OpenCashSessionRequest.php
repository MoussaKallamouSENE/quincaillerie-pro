<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OpenCashSessionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * L'authentification est assuree par le middleware `auth:sanctum` applique
     * au groupe de routes ; la validation porte sur le fond de caisse.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'opening_balance' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opening_balance.required' => 'Le fond de caisse est obligatoire.',
            'opening_balance.numeric' => 'Le fond de caisse doit être un nombre.',
            'opening_balance.min' => 'Le fond de caisse ne peut pas être négatif.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'opening_balance' => 'fond de caisse',
        ];
    }
}
