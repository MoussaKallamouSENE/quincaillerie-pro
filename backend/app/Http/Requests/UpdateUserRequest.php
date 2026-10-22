<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Seuls les comptes autorises a gerer les utilisateurs peuvent en modifier.
     */
    public function authorize(): bool
    {
        return Gate::allows('manage-users');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Tous les champs sont facultatifs : seuls les champs transmis sont
     * modifies. Un mot de passe vide conserve le mot de passe actuel.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
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
            'email.email' => 'L’adresse e-mail doit être valide.',
            'email.unique' => 'Un compte utilise déjà cette adresse e-mail.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'role.exists' => 'Le rôle sélectionné n’existe pas.',
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
            'email' => 'adresse e-mail',
            'phone' => 'téléphone',
            'password' => 'mot de passe',
            'role' => 'rôle',
            'is_active' => 'état',
        ];
    }

    /**
     * Refuse la requete avec un message en francais.
     */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Vous n’avez pas la permission de gérer les utilisateurs.');
    }
}
