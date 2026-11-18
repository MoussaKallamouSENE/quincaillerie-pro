<?php

namespace App\Http\Requests;

use App\Models\Refund;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReconcileRefundRequest extends FormRequest
{
    /**
     * L'authentification, la permission et les regles metier sont verifiees
     * par la route et le service ; la validation porte sur la decision.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise les champs libres avant validation.
     */
    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (is_string($reason)) {
            $reason = trim($reason);

            $this->merge(['reason' => $reason === '' ? null : $reason]);
        }

        $reference = $this->input('reconciliation_reference');

        if (is_string($reference)) {
            $reference = trim($reference);

            $this->merge(['reconciliation_reference' => $reference === '' ? null : $reference]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $requiresReason = fn (): bool => in_array(
            $this->input('decision'),
            [Refund::RECONCILIATION_ABSENT, Refund::RECONCILIATION_PENDING],
            true,
        );

        return [
            'decision' => ['required', 'string', Rule::in(Refund::RECONCILIATION_DECISIONS)],
            'reason' => [
                Rule::requiredIf($requiresReason),
                'nullable',
                'string',
                'max:500',
            ],
            'reconciliation_reference' => ['nullable', 'string', 'max:255'],
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
            'decision.required' => 'La décision de réconciliation est obligatoire.',
            'decision.string' => 'La décision de réconciliation doit être une chaîne de caractères.',
            'decision.in' => 'La décision de réconciliation est invalide.',
            'reason.required' => 'Le motif est obligatoire pour une absence ou une incertitude.',
            'reason.string' => 'Le motif doit être une chaîne de caractères.',
            'reason.max' => 'Le motif ne peut pas dépasser 500 caractères.',
            'reconciliation_reference.string' => 'La référence de réconciliation doit être une chaîne de caractères.',
            'reconciliation_reference.max' => 'La référence de réconciliation ne peut pas dépasser 255 caractères.',
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
            'decision' => 'décision de réconciliation',
            'reason' => 'motif',
            'reconciliation_reference' => 'référence de réconciliation',
        ];
    }
}
