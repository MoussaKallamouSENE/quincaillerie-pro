<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * L'authentification est assuree par le middleware `auth:sanctum` applique
     * au groupe de routes ; la validation porte sur les donnees du produit.
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
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:255', 'unique:products,sku'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'min_stock_alert' => ['required', 'integer', 'min:0'],
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
            'name.required' => 'Le nom du produit est obligatoire.',
            'sku.required' => 'La référence (SKU) est obligatoire.',
            'sku.unique' => 'Cette référence (SKU) est déjà utilisée.',
            'category_id.required' => 'La catégorie est obligatoire.',
            'category_id.exists' => 'La catégorie sélectionnée n’existe pas.',
            'unit_id.required' => 'L’unité est obligatoire.',
            'unit_id.exists' => 'L’unité sélectionnée n’existe pas.',
            'purchase_price.required' => 'Le prix d’achat est obligatoire.',
            'sale_price.required' => 'Le prix de vente est obligatoire.',
            'stock_quantity.required' => 'La quantité en stock est obligatoire.',
            'min_stock_alert.required' => 'Le seuil d’alerte de stock est obligatoire.',
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
            'sku' => 'référence',
            'category_id' => 'catégorie',
            'unit_id' => 'unité',
            'purchase_price' => 'prix d’achat',
            'sale_price' => 'prix de vente',
            'stock_quantity' => 'quantité en stock',
            'min_stock_alert' => 'seuil d’alerte',
            'is_active' => 'état',
        ];
    }
}
