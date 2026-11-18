<?php

namespace App\Http\Requests;

use App\Models\Refund;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRefundRequest extends FormRequest
{
    /**
     * L'authentification et la permission sont verifiees par les middlewares
     * de la route ; la validation porte sur la demande de remboursement.
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
        $transactionReference = $this->input('transaction_reference');

        if (is_string($transactionReference)) {
            $this->merge(['transaction_reference' => trim($transactionReference)]);
        }

        $reason = $this->input('reason');

        if (is_string($reason)) {
            $this->merge(['reason' => trim($reason)]);
        }

        $idempotencyKey = $this->input('idempotency_key');

        if (is_string($idempotencyKey)) {
            $this->merge(['idempotency_key' => trim($idempotencyKey)]);
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
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'payment_id' => ['required', 'integer', 'exists:payments,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'method' => ['required', 'string', Rule::in(Refund::METHODS)],
            'transaction_reference' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn (): bool => Refund::requiresTransactionReference((string) $this->input('method'))),
            ],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:64'],
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
            'sale_id.required' => 'La vente associée est obligatoire.',
            'sale_id.integer' => 'La vente associée est invalide.',
            'sale_id.exists' => 'Cette vente n’existe pas.',
            'payment_id.required' => 'Le paiement associé est obligatoire.',
            'payment_id.integer' => 'Le paiement associé est invalide.',
            'payment_id.exists' => 'Ce paiement n’existe pas.',
            'amount.required' => 'Le montant du remboursement est obligatoire.',
            'amount.numeric' => 'Le montant du remboursement doit être un nombre.',
            'amount.gt' => 'Le montant du remboursement doit être supérieur à zéro.',
            'amount.max' => 'Le montant du remboursement est trop élevé.',
            'amount.decimal' => 'Le montant du remboursement ne peut pas avoir plus de deux décimales.',
            'method.required' => 'Le moyen de remboursement est obligatoire.',
            'method.in' => 'Le moyen de remboursement est invalide.',
            'transaction_reference.required' => 'La référence de transaction est obligatoire pour Wave, Orange Money et les virements bancaires.',
            'transaction_reference.string' => 'La référence de transaction doit être une chaîne de caractères.',
            'transaction_reference.max' => 'La référence de transaction ne peut pas dépasser 255 caractères.',
            'reason.required' => 'Le motif du remboursement est obligatoire.',
            'reason.string' => 'Le motif du remboursement doit être une chaîne de caractères.',
            'reason.max' => 'Le motif du remboursement ne peut pas dépasser 500 caractères.',
            'idempotency_key.required' => 'La clé d’idempotence est obligatoire.',
            'idempotency_key.string' => 'La clé d’idempotence doit être une chaîne de caractères.',
            'idempotency_key.max' => 'La clé d’idempotence ne peut pas dépasser 64 caractères.',
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
            'sale_id' => 'vente',
            'payment_id' => 'paiement',
            'amount' => 'montant',
            'method' => 'moyen de remboursement',
            'transaction_reference' => 'référence de transaction',
            'reason' => 'motif',
            'idempotency_key' => 'clé d’idempotence',
        ];
    }
}
