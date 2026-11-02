<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
{
    /**
     * Seuls les administrateurs et les gerants peuvent modifier une categorie.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasAnyRole(['Admin', 'Gérant']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('categories', 'name')->ignore($category?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Interdit les cycles dans la hierarchie des categories.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->filled('parent_id')) {
                    return;
                }

                $category = $this->route('category');

                if (! $category instanceof Category) {
                    return;
                }

                $parentId = (int) $this->input('parent_id');

                if ($parentId === $category->id) {
                    $validator->errors()->add(
                        'parent_id',
                        'Une catégorie ne peut pas être sa propre catégorie parente.',
                    );

                    return;
                }

                $parent = Category::find($parentId);
                $visited = [];

                while ($parent !== null) {
                    if ($parent->id === $category->id) {
                        $validator->errors()->add(
                            'parent_id',
                            'Une catégorie ne peut pas avoir pour parente l’une de ses sous-catégories.',
                        );

                        return;
                    }

                    if (isset($visited[$parent->id])) {
                        return;
                    }

                    $visited[$parent->id] = true;
                    $parent = $parent->parent;
                }
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
            'name.unique' => 'Une catégorie porte déjà ce nom.',
            'parent_id.exists' => 'La catégorie parente sélectionnée n’existe pas.',
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
            'description' => 'description',
            'parent_id' => 'catégorie parente',
            'is_active' => 'état',
        ];
    }
}
