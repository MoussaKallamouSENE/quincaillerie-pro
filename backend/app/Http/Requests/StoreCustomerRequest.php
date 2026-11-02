<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCustomerRequest extends FormRequest
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
     * Le solde du client (`outstanding_balance`) n'est pas modifiable via
     * l'API : il decoule des ventes et des reglements enregistres.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'credit_limit' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Empeche un vendeur de fixer un plafond de credit.
     *
     * Un plafond a zero est la valeur par defaut de l'application : il reste
     * accepte pour ne pas bloquer la creation normale d'un client a la caisse.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->user()?->hasAnyRole(['Admin', 'Gérant'])) {
                    return;
                }

                if (! $this->has('credit_limit')) {
                    return;
                }

                $creditLimit = $this->input('credit_limit');

                if (is_numeric($creditLimit) && (float) $creditLimit === 0.0) {
                    return;
                }

                $validator->errors()->add(
                    'credit_limit',
                    'Seuls un administrateur ou un gérant peuvent définir un plafond de crédit.',
                );
            },
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
            'name.required' => 'Le nom du client est obligatoire.',
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
