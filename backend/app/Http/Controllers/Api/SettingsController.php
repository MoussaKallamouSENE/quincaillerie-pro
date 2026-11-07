<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCompanySettingsRequest;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class SettingsController extends Controller
{
    public function company(): JsonResponse
    {
        return response()->json(['settings' => $this->companySettings()]);
    }

    public function updateCompany(UpdateCompanySettingsRequest $request): JsonResponse
    {
        $settings = array_merge($this->companySettings(), $request->validated(), ['currency' => 'XOF']);
        Setting::updateOrCreate(
            ['key' => 'company.profile'],
            ['value' => $settings, 'type' => 'json', 'group' => 'company'],
        );

        return response()->json(['settings' => $settings, 'message' => 'Paramètres enregistrés.']);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole('Admin'), Response::HTTP_FORBIDDEN);
        $data = $request->validate(['logo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $path = $data['logo']->store('company-logos', 'local');
        $settings = array_merge($this->companySettings(), ['logo_path' => $path, 'currency' => 'XOF']);
        Setting::updateOrCreate(['key' => 'company.profile'], ['value' => $settings, 'type' => 'json', 'group' => 'company']);

        return response()->json(['settings' => $settings, 'message' => 'Logo enregistré.'], Response::HTTP_CREATED);
    }

    public function logo(Request $request): Response
    {
        abort_unless($request->user()?->hasAnyRole(['Admin', 'Gérant']), Response::HTTP_FORBIDDEN);
        $path = $this->companySettings()['logo_path'] ?? null;
        abort_unless(is_string($path) && str_starts_with($path, 'company-logos/'), Response::HTTP_NOT_FOUND);
        abort_unless(Storage::disk('local')->exists($path), Response::HTTP_NOT_FOUND);

        return Storage::disk('local')->response($path, headers: ['Cache-Control' => 'private, no-store']);
    }

    private function companySettings(): array
    {
        $value = Setting::query()->where('key', 'company.profile')->value('value');
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return array_merge([
            'business_name' => config('quincaillerie.name', 'Quincaillerie Pro'),
            'phone' => '', 'email' => '', 'address' => '', 'invoice_footer' => 'Merci de votre confiance.',
            'currency' => 'XOF', 'logo_path' => null,
        ], is_array($value) ? $value : []);
    }
}
