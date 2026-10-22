<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation publique d'un utilisateur.
 *
 * `role` reprend le premier rôle Spatie pour faciliter l'affichage dans un
 * tableau (un seul rôle par compte dans cette application), `roles` conserve la
 * liste complète.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transformer the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'role' => $this->roles->first()?->name,
            'roles' => $this->roles->pluck('name')->values()->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
