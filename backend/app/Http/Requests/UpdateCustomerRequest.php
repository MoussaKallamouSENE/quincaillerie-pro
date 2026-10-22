<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * L'authentification est assuree par le middleware `auth:sanctum` applique
     * au groupe de routes ; la validation porte sur les donnees du client.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Tous les champs sont facultatifs : seuls les champs transmis sont
     * modifies. Le solde du client (`outstanding_balance`) n'est pas modifiable
     * via l'API : il decoule des ventes et des reglements enregistres.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'credit_limit' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
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
            'email.email' => 'L’adresse e-mail du client doit être valide.',
            'credit_limit.numeric' => 'Le plafond de crédit doit être un nombre.',
            'credit_limit.min' => 'Le plafond de crédit ne peut pas être négatif.',
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
            'name' => 'nom',
            'phone' => 'téléphone',
            'email' => 'adresse e-mail',
            'address' => 'adresse',
            'credit_limit' => 'plafond de crédit',
            'is_active' => 'état',
        ];
    }
}
