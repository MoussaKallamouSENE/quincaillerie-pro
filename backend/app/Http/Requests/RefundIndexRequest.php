<?php

namespace App\Http\Requests;

use App\Models\Refund;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefundIndexRequest extends FormRequest
{
    /**
     * L'authentification est verifiee par la route ; la validation porte sur
     * les filtres de la liste des remboursements.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise les filtres vides.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['status', 'method', 'search', 'sale_id', 'payment_id', 'page', 'per_page'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '') {
                $value = null;
            }

            $normalized[$field] = $value;
        }

        $this->merge($normalized);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'nullable',
                'string',
                Rule::in([...Refund::STATUSES, 'all']),
            ],
            'method' => ['nullable', 'string', Rule::in(Refund::METHODS)],
            'sale_id' => ['nullable', 'integer', 'min:1'],
            'payment_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
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
            'status.string' => 'Le statut demandé doit être une chaîne de caractères.',
            'status.in' => 'Le statut demandé est invalide.',
            'method.string' => 'Le moyen de remboursement demandé doit être une chaîne de caractères.',
            'method.in' => 'Le moyen de remboursement demandé est invalide.',
            'sale_id.integer' => 'La vente demandée doit être un identifiant entier.',
            'sale_id.min' => 'La vente demandée est invalide.',
            'payment_id.integer' => 'Le paiement demandé doit être un identifiant entier.',
            'payment_id.min' => 'Le paiement demandé est invalide.',
            'search.string' => 'La recherche doit être une chaîne de caractères.',
            'search.max' => 'La recherche ne peut pas dépasser 255 caractères.',
            'page.integer' => 'Le numéro de page doit être un entier.',
            'page.min' => 'Le numéro de page doit être supérieur ou égal à 1.',
            'per_page.integer' => 'Le nombre d’éléments par page doit être un entier.',
            'per_page.min' => 'Le nombre d’éléments par page doit être supérieur ou égal à 1.',
            'per_page.max' => 'Le nombre d’éléments par page ne peut pas dépasser 100.',
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
            'status' => 'statut',
            'method' => 'moyen de remboursement',
            'sale_id' => 'vente',
            'payment_id' => 'paiement',
            'search' => 'recherche',
            'page' => 'page',
            'per_page' => 'nombre d’éléments par page',
        ];
    }
}
