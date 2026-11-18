<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RejectManualPaymentRequest extends FormRequest
{
    /**
     * L'authentification et le role sont verifies par les middlewares de la
     * route ; la validation porte sur le motif du rejet.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise le motif avant validation.
     */
    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (is_string($reason)) {
            $this->merge(['reason' => trim($reason)]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
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
            'reason.required' => 'Le motif du rejet est obligatoire.',
            'reason.string' => 'Le motif du rejet doit être une chaîne de caractères.',
            'reason.max' => 'Le motif du rejet ne peut pas dépasser 500 caractères.',
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
            'reason' => 'motif du rejet',
        ];
    }
}
