<?php

namespace App\Http\Requests;

use App\Models\Refund;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelRefundRequest extends FormRequest
{
    /**
     * L'authentification, la permission et les regles metier sont verifiees
     * par la route et le service ; la validation porte sur le motif.
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
            $reason = trim($reason);

            $this->merge(['reason' => $reason === '' ? null : $reason]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $refund = $this->route('refund');
        $requiresReason = $refund instanceof Refund && $refund->status === Refund::STATUS_APPROVED;

        return [
            'reason' => [
                Rule::requiredIf($requiresReason),
                'nullable',
                'string',
                'max:500',
            ],
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
            'reason.required' => 'Le motif d’annulation est obligatoire pour un remboursement approuvé.',
            'reason.string' => 'Le motif d’annulation doit être une chaîne de caractères.',
            'reason.max' => 'Le motif d’annulation ne peut pas dépasser 500 caractères.',
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
            'reason' => 'motif d’annulation',
        ];
    }
}
