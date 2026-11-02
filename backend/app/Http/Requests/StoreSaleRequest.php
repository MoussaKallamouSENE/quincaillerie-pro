<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * L'authentification est assuree par le middleware `auth:sanctum` applique
     * au groupe de routes ; la validation porte sur les lignes de la vente et
     * sur le paiement.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Le client est facultatif : il devient obligatoire lorsqu'une partie du
     * montant reste due, ce que le service verifie une fois le total connu.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'nullable', 'integer', 'exists:customers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:64'],
            'payment' => ['sometimes', 'nullable', 'array'],
            'payment.method' => ['required_with:payment', 'string', 'in:cash,wave,orange_money,bank_transfer,check'],
            'payment.amount' => ['required_with:payment', 'numeric', 'min:0'],
            'payment.reference' => ['sometimes', 'nullable', 'string', 'max:255'],
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
            'customer_id.exists' => 'Ce client n’existe pas.',
            'items.required' => 'La vente doit contenir au moins une ligne.',
            'items.min' => 'La vente doit contenir au moins une ligne.',
            'items.*.product_id.required' => 'Le produit est obligatoire.',
            'items.*.product_id.exists' => 'Ce produit n’existe pas.',
            'items.*.quantity.required' => 'La quantité est obligatoire.',
            'items.*.quantity.min' => 'La quantité doit être supérieure à zéro.',
            'items.*.unit_price.numeric' => 'Le prix unitaire doit être un nombre.',
            'items.*.unit_price.gt' => 'Le prix unitaire doit être supérieur à zéro.',
            'payment.method.required' => 'Le mode de paiement est obligatoire.',
            'payment.method.in' => 'Le mode de paiement est invalide.',
            'payment.amount.required' => 'Le montant payé est obligatoire.',
            'payment.amount.numeric' => 'Le montant payé doit être un nombre.',
            'payment.amount.min' => 'Le montant payé ne peut pas être négatif.',
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
            'customer_id' => 'client',
            'items' => 'lignes de vente',
            'payment' => 'paiement',
            'payment.method' => 'mode de paiement',
            'payment.amount' => 'montant payé',
            'payment.reference' => 'référence du paiement',
            'idempotency_key' => 'clé d’idempotence',
        ];
    }
}
