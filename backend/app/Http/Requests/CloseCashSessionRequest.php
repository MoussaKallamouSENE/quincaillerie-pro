<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CloseCashSessionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * L'authentification est assuree par le middleware `auth:sanctum` applique
     * au groupe de routes ; la validation porte sur le montant compté.
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
            'closing_balance' => ['required', 'numeric', 'min:0'],
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
            'closing_balance.required' => 'Le montant de clôture est obligatoire.',
            'closing_balance.numeric' => 'Le montant de clôture doit être un nombre.',
            'closing_balance.min' => 'Le montant de clôture ne peut pas être négatif.',
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
            'closing_balance' => 'montant de clôture',
        ];
    }
}
