<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'sku' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('products', 'sku')->ignore($this->route('product')),
            ],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'unit_id' => ['sometimes', 'integer', 'exists:units,id'],
            'purchase_price' => ['sometimes', 'numeric', 'min:0'],
            'sale_price' => ['sometimes', 'numeric', 'min:0'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'min_stock_alert' => ['sometimes', 'integer', 'min:0'],
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
            'sku.unique' => 'Cette référence (SKU) est déjà utilisée.',
            'category_id.exists' => 'La catégorie sélectionnée n’existe pas.',
            'unit_id.exists' => 'L’unité sélectionnée n’existe pas.',
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
