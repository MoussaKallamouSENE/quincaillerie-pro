<?php

namespace App\Http\Requests;

use App\Models\Refund;
use App\Models\RefundDocument;
use App\Models\User;
use App\Services\RefundAccessService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRefundDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $refund = $this->route('refund');
        $user = $this->user();

        return $refund instanceof Refund
            && $user instanceof User
            && app(RefundAccessService::class)->canUpload($user, $refund);
    }

    protected function prepareForValidation(): void
    {
        $kind = $this->input('kind');

        if (is_string($kind)) {
            $this->merge(['kind' => trim($kind)]);
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
            'kind' => ['required', 'string', Rule::in(RefundDocument::KINDS)],
            'file' => ['required', 'file', 'max:5120'],
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
            'kind.required' => 'Le type de document est obligatoire.',
            'kind.string' => 'Le type de document doit être une chaîne de caractères.',
            'kind.in' => 'Le type de document est invalide.',
            'file.required' => 'Le document est obligatoire.',
            'file.file' => 'Le document doit être un fichier valide.',
            'file.max' => 'Le document ne peut pas dépasser 5 Mo.',
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
            'kind' => 'type de document',
            'file' => 'document',
        ];
    }
}
