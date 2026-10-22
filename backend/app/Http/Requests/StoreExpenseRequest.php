<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * L'authentification est assuree par le middleware `auth:sanctum` applique
     * au groupe de routes ; la validation porte sur la depense elle-meme.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `cash_session_id` est accepte mais ne sert jamais au rattachement : la
     * depense est toujours liee a la session de caisse ouverte de
     * l'utilisateur. La date est facultative et vaut le jour du jour par
     * defaut.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'cash_session_id' => ['sometimes', 'nullable', 'integer', 'exists:cash_sessions,id'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
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
            'name.required' => 'Le nom de la dépense est obligatoire.',
            'name.string' => 'Le nom de la dépense doit être un texte.',
            'name.max' => 'Le nom de la dépense ne peut pas dépasser 255 caractères.',
            'amount.required' => 'Le montant de la dépense est obligatoire.',
            'amount.numeric' => 'Le montant de la dépense doit être un nombre.',
            'amount.min' => 'Le montant de la dépense ne peut pas être négatif.',
            'cash_session_id.integer' => 'La session de caisse doit être un identifiant.',
            'cash_session_id.exists' => 'Cette session de caisse n’existe pas.',
            'date.date_format' => 'La date de la dépense est invalide.',
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
            'name' => 'nom de la dépense',
            'amount' => 'montant de la dépense',
            'cash_session_id' => 'session de caisse',
            'date' => 'date de la dépense',
        ];
    }
}
