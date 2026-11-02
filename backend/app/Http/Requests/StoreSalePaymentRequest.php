<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSalePaymentRequest extends FormRequest
{
    /**
     * L'authentification et le rôle sont verifies par les middlewares de la
     * route ; la validation porte sur le règlement.
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
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string', 'in:cash,wave,orange_money,bank_transfer,check'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
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
            'amount.required' => 'Le montant du règlement est obligatoire.',
            'amount.numeric' => 'Le montant du règlement doit être un nombre.',
            'amount.gt' => 'Le montant du règlement doit être supérieur à zéro.',
            'method.required' => 'Le mode de paiement est obligatoire.',
            'method.in' => 'Le mode de paiement est invalide.',
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
            'amount' => 'montant',
            'method' => 'mode de paiement',
            'reference' => 'référence',
        ];
    }
}
