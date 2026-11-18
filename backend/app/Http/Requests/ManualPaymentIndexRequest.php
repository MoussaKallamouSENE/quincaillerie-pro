<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManualPaymentIndexRequest extends FormRequest
{
    /**
     * L'authentification et le rôle sont vérifiés par les middlewares de la
     * route ; la validation porte sur les filtres de la file de vérification.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise les filtres avant validation.
     */
    protected function prepareForValidation(): void
    {
        $status = $this->input('status');
        $status = is_string($status) ? trim($status) : $status;

        if ($status === null || $status === '') {
            $status = Payment::STATUS_PENDING;
        }

        $method = $this->input('method');
        $method = is_string($method) ? trim($method) : $method;

        if ($method === '') {
            $method = null;
        }

        $search = $this->input('search');
        $search = is_string($search) ? trim($search) : $search;

        if ($search === '') {
            $search = null;
        }

        $pagination = [];

        foreach (['page', 'per_page'] as $field) {
            if ($this->input($field) === '') {
                $pagination[$field] = null;
            }
        }

        $this->merge([
            'status' => $status,
            'method' => $method,
            'search' => $search,
            ...$pagination,
        ]);
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
                'required',
                'string',
                Rule::in([
                    'all',
                    Payment::STATUS_PENDING,
                    Payment::STATUS_CONFIRMED,
                    Payment::STATUS_REJECTED,
                ]),
            ],
            'method' => ['nullable', 'string', Rule::in(Payment::MANUAL_METHODS)],
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
            'status.required' => 'Le statut demandé est obligatoire.',
            'status.string' => 'Le statut demandé doit être une chaîne de caractères.',
            'status.in' => 'Le statut demandé est invalide.',
            'method.string' => 'Le moyen de paiement demandé doit être une chaîne de caractères.',
            'method.in' => 'Le moyen de paiement demandé est invalide.',
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
            'method' => 'moyen de paiement',
            'search' => 'recherche',
            'page' => 'page',
            'per_page' => 'nombre d’éléments par page',
        ];
    }
}
