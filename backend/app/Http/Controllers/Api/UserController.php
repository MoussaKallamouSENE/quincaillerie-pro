<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    /**
     * Nombre maximum d'utilisateurs renvoyes par page.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Relations qui traduisent une activite enregistree par l'utilisateur.
     *
     * La suppression d'un compte est refusee des qu'une de ces relations
     * existe : l'historique (ventes, caisse, stock...) doit rester rattache a
     * son auteur. Le compte est alors desactive.
     *
     * @var list<string>
     */
    private const ACTIVITY_RELATIONS = [
        'sales',
        'payments',
        'cashSessions',
        'cashMovements',
        'refundsRequested',
        'refundsApproved',
        'refundsRejected',
        'refundsExecuted',
        'refundsCancelled',
        'refundsReconciled',
        'refundDocumentsUploaded',
        'expenses',
        'purchases',
        'receptions',
        'stockMovements',
    ];

    /**
     * Display a paginated listing of the users.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->assertCanManageUsers();

        $search = trim($request->string('search')->toString());

        $users = User::query()
            ->with('roles')
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when(
                $request->filled('role'),
                fn (Builder $query) => $query->whereHas(
                    'roles',
                    fn (Builder $query) => $query->where('name', $request->string('role')->toString()),
                ),
            )
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return UserResource::collection($users);
    }

    /**
     * Store a newly created user.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->assertCanManageUsers();

        $data = $request->validated();

        $user = DB::transaction(function () use ($data, $request): User {
            $role = $this->roleForAssignment($data['role'], $request->user());

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $user->assignRole($role);

            return $user;
        });

        return UserResource::make($user->load('roles'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user): UserResource
    {
        $this->assertCanManageUsers();

        return UserResource::make($user->load('roles'));
    }

    /**
     * Update the specified user.
     *
     * Le mot de passe n'est modifie que s'il est transmis : le formulaire
     * d'edition le laisse vide pour conserver le mot de passe actuel.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->assertCanManageUsers();

        $data = $request->validated();

        DB::transaction(function () use ($data, $request, $user): void {
            $role = isset($data['role']) ? $this->roleForAssignment($data['role'], $request->user(), $user) : null;
            $user->fill(Arr::only($data, ['name', 'email', 'phone', 'is_active']));

            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            $user->save();

            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                // Un compte desactive ne doit plus pouvoir utiliser ses jetons existants.
                $user->tokens()->delete();
            }

            if ($role !== null) {
                $user->syncRoles([$role]);
            }
        });

        return UserResource::make($user->load('roles'))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Remove the specified user.
     *
     * Un compte ne peut pas se supprimer lui-meme (verrouillage de la session en
     * cours) et un compte ayant enregistre de l'activite est conserve : il doit
     * etre desactive.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->assertCanManageUsers();

        if ($user->is($request->user())) {
            return response()->json([
                'message' => 'Vous ne pouvez pas supprimer votre propre compte.',
            ], Response::HTTP_CONFLICT);
        }

        if ($user->hasRole('Admin') && ! $request->user()->hasRole('Admin')) {
            throw new AuthorizationException('Seul un administrateur peut supprimer un compte administrateur.');
        }

        foreach (self::ACTIVITY_RELATIONS as $relation) {
            if ($user->{$relation}()->exists()) {
                return response()->json([
                    'message' => 'Cet utilisateur a enregistré de l’activité (ventes, caisse, stock...). Désactivez-le plutôt que de le supprimer.',
                ], Response::HTTP_CONFLICT);
            }
        }

        $user->delete();

        return response()->json([
            'message' => 'Utilisateur supprimé.',
        ]);
    }

    /**
     * Autorise le rôle qui sera réellement attribué, indépendamment de la
     * collation utilisée pour retrouver son nom en base.
     */
    private function roleForAssignment(string $name, User $actor, ?User $target = null): Role
    {
        // La contrainte unique (name, guard_name) peut être insensible à la
        // casse selon la collation MySQL. Le verrou FOR UPDATE serialise les
        // modifications concurrentes du rôle ; la comparaison stricte PHP
        // garantit ensuite que le nom réellement attribué est exactement celui
        // demandé (casse, accents, caractères Unicode).
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $name)
            ->lockForUpdate()
            ->first();

        if ($role === null || $role->name !== $name) {
            throw ValidationException::withMessages(['role' => 'Le rôle sélectionné n’existe pas.']);
        }

        if ($role->name === 'Admin' && ! $actor->hasRole('Admin')) {
            throw new AuthorizationException('Seul un administrateur peut attribuer le rôle Admin.');
        }

        if ($target?->is($actor)) {
            $currentRoles = $target->getRoleNames()
                ->values()
                ->sort()
                ->values()
                ->all();

            if ($currentRoles !== [$role->name]) {
                throw new AuthorizationException('Vous ne pouvez pas modifier votre propre rôle.');
            }
        }

        return $role;
    }

    /**
     * Refuse l'acces aux comptes qui ne peuvent pas gerer les utilisateurs.
     */
    private function assertCanManageUsers(): void
    {
        if (Gate::denies('gerer_utilisateurs')) {
            throw new AuthorizationException('Vous n’avez pas la permission de gérer les utilisateurs.');
        }
    }

    /**
     * Nombre d'utilisateurs par page demande, borne a une plage raisonnable.
     */
    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 15), 1), self::MAX_PER_PAGE);
    }
}
