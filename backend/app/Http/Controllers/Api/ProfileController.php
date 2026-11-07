<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeOwnPasswordRequest;
use App\Http\Requests\UpdateOwnProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->only(['id', 'name', 'email', 'phone'])]);
    }

    public function update(UpdateOwnProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->validated())->save();

        return response()->json(['user' => $user->only(['id', 'name', 'email', 'phone']), 'message' => 'Profil mis à jour.']);
    }

    public function changePassword(ChangeOwnPasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['password' => Hash::make($request->validated('password'))])->save();
        $currentToken = $user->currentAccessToken();
        if ($currentToken instanceof PersonalAccessToken) {
            $user->tokens()->where('id', '!=', $currentToken->getKey())->delete();
        } else {
            $user->tokens()->delete();
        }

        return response()->json(['message' => 'Mot de passe modifié. Les autres sessions ont été révoquées.']);
    }
}
