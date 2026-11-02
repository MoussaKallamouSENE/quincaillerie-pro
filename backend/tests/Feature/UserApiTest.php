<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cree (ou retrouve) un role Spatie.
     */
    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name]);
    }

    /**
     * Cree les roles de l'application.
     */
    private function createRoles(): void
    {
        foreach (['Admin', 'Gérant', 'Employé'] as $role) {
            $this->role($role);
        }
    }

    /**
     * Authentifie l'utilisateur de la session avec le role demande.
     *
     * Les roles sont attribues avant `Sanctum::actingAs` : Spatie determine le
     * guard courant et refuserait un role `web` sur un guard `sanctum`.
     */
    private function authenticateAs(string $role = 'Admin'): User
    {
        $this->createRoles();

        $user = User::factory()->create();
        $user->assignRole($role);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Charge utile valide pour la creation d'un utilisateur.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Awa Diop',
            'email' => 'awa.diop@quincaillerie.com',
            'phone' => '+221 77 123 45 67',
            'password' => 'motdepasse',
            'role' => 'Employé',
        ], $overrides);
    }

    public function test_guests_cannot_list_users(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
    }

    public function test_employees_cannot_manage_users(): void
    {
        $this->authenticateAs('Employé');

        $target = User::factory()->create();

        $this->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/users', $this->payload())->assertForbidden();
        $this->getJson("/api/users/{$target->id}")->assertForbidden();
        $this->putJson("/api/users/{$target->id}", $this->payload())->assertForbidden();
        $this->deleteJson("/api/users/{$target->id}")->assertForbidden();
    }

    public function test_users_are_listed_with_their_role(): void
    {
        $employee = User::factory()->create([
            'name' => 'Awa Diop',
            'email' => 'awa.diop@quincaillerie.com',
            'phone' => '+221 77 123 45 67',
        ]);
        $employee->assignRole($this->role('Employé'));

        $this->authenticateAs('Admin');

        $response = $this->getJson('/api/users');

        $response->assertOk()
            ->assertJsonFragment([
                'name' => 'Awa Diop',
                'email' => 'awa.diop@quincaillerie.com',
                'phone' => '+221 77 123 45 67',
                'role' => 'Employé',
                'roles' => ['Employé'],
                'is_active' => true,
            ])
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'name',
                    'email',
                    'phone',
                    'is_active',
                    'role',
                    'roles',
                    'created_at',
                    'updated_at',
                ]],
                'links',
                'meta',
            ]);
    }

    public function test_users_can_be_searched_by_name_or_email(): void
    {
        $this->authenticateAs('Admin');

        User::factory()->create(['name' => 'Moussa Ndiaye', 'email' => 'moussa@quincaillerie.com']);
        User::factory()->create(['name' => 'Fatou Sow', 'email' => 'fatou@quincaillerie.com']);

        $this->getJson('/api/users?search=fatou')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Fatou Sow');
    }

    public function test_a_manager_can_create_a_user(): void
    {
        $this->authenticateAs('Gérant');

        $response = $this->postJson('/api/users', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Awa Diop')
            ->assertJsonPath('data.email', 'awa.diop@quincaillerie.com')
            ->assertJsonPath('data.phone', '+221 77 123 45 67')
            ->assertJsonPath('data.role', 'Employé')
            ->assertJsonPath('data.is_active', true);

        $created = User::where('email', 'awa.diop@quincaillerie.com')->firstOrFail();

        $this->assertTrue(Hash::check('motdepasse', $created->password));
        $this->assertTrue($created->hasRole('Employé'));
    }

    public function test_user_creation_validates_input(): void
    {
        $this->authenticateAs('Admin');

        User::factory()->create(['email' => 'awa.diop@quincaillerie.com']);

        $response = $this->postJson('/api/users', [
            'email' => 'awa.diop@quincaillerie.com',
            'password' => 'court',
            'role' => 'Inconnu',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'role']);
    }

    public function test_a_user_can_be_updated(): void
    {
        $user = User::factory()->create([
            'name' => 'Ancien Nom',
            'email' => 'ancien@quincaillerie.com',
            'phone' => '+221 77 000 00 00',
            'password' => 'motdepasse',
        ]);
        $user->assignRole($this->role('Employé'));

        $this->authenticateAs('Admin');

        $response = $this->putJson("/api/users/{$user->id}", [
            'name' => 'Nouveau Nom',
            'email' => 'nouveau@quincaillerie.com',
            'phone' => '+221 78 111 11 11',
            'role' => 'Gérant',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Nouveau Nom')
            ->assertJsonPath('data.role', 'Gérant');

        $user->refresh();

        $this->assertSame('+221 78 111 11 11', $user->phone);
        $this->assertTrue($user->hasRole('Gérant'));
        $this->assertFalse($user->hasRole('Employé'));

        // Le mot de passe n'est pas transmis : il est conserve.
        $this->assertTrue(Hash::check('motdepasse', $user->password));
    }

    public function test_a_user_password_can_be_changed(): void
    {
        $this->authenticateAs('Admin');

        $user = User::factory()->create(['password' => 'motdepasse']);

        $this->putJson("/api/users/{$user->id}", ['password' => 'nouveaupasse'])
            ->assertOk();

        $this->assertTrue(Hash::check('nouveaupasse', $user->refresh()->password));
    }

    public function test_a_user_without_activity_can_be_deleted(): void
    {
        $this->authenticateAs('Admin');

        $user = User::factory()->create();

        $this->deleteJson("/api/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Utilisateur supprimé.');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_a_user_cannot_delete_their_own_account(): void
    {
        $admin = $this->authenticateAs('Admin');

        $this->deleteJson("/api/users/{$admin->id}")->assertConflict();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_a_user_with_recorded_activity_cannot_be_deleted(): void
    {
        $this->authenticateAs('Admin');

        $user = User::factory()->create();
        Sale::factory()->create(['user_id' => $user->id]);

        $this->deleteJson("/api/users/{$user->id}")->assertConflict();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_a_user_granted_the_permission_can_manage_users(): void
    {
        $user = User::factory()->create();
        $user->assignRole($this->role('Employé'));
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'gerer_utilisateurs']));

        Sanctum::actingAs($user);

        $this->getJson('/api/users')->assertOk();
    }

    public function test_a_manager_cannot_create_an_admin(): void
    {
        $this->authenticateAs('Gérant');

        $this->postJson('/api/users', $this->payload([
            'email' => 'admin.cree@quincaillerie.com',
            'role' => 'Admin',
        ]))->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'admin.cree@quincaillerie.com']);
    }

    public function test_an_admin_can_create_an_admin(): void
    {
        $this->authenticateAs('Admin');

        $this->postJson('/api/users', $this->payload([
            'email' => 'admin.cree@quincaillerie.com',
            'role' => 'Admin',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.role', 'Admin');

        $created = User::where('email', 'admin.cree@quincaillerie.com')->firstOrFail();

        $this->assertTrue($created->hasRole('Admin'));
    }

    public function test_a_manager_cannot_update_an_admin(): void
    {
        $this->authenticateAs('Gérant');

        $admin = User::factory()->create(['password' => 'motdepasse']);
        $admin->assignRole($this->role('Admin'));

        $this->putJson("/api/users/{$admin->id}", [
            'name' => 'Nom modifié',
            'password' => 'nouveaupasse',
            'role' => 'Employé',
            'is_active' => false,
        ])->assertForbidden();

        $admin->refresh();

        $this->assertNotSame('Nom modifié', $admin->name);
        $this->assertTrue(Hash::check('motdepasse', $admin->password));
        $this->assertTrue($admin->hasRole('Admin'));
        $this->assertTrue($admin->is_active);
    }

    public function test_a_manager_cannot_delete_an_admin(): void
    {
        $this->authenticateAs('Gérant');

        $admin = User::factory()->create();
        $admin->assignRole($this->role('Admin'));

        $this->deleteJson("/api/users/{$admin->id}")->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_a_manager_can_update_an_employee(): void
    {
        $this->authenticateAs('Gérant');

        $employee = User::factory()->create();
        $employee->assignRole($this->role('Employé'));

        $this->putJson("/api/users/{$employee->id}", ['name' => 'Nouveau Nom'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nouveau Nom');
    }

    public function test_a_user_cannot_change_their_own_role(): void
    {
        $admin = $this->authenticateAs('Admin');

        $this->putJson("/api/users/{$admin->id}", ['role' => 'Gérant'])
            ->assertForbidden();

        $this->assertTrue($admin->refresh()->hasRole('Admin'));
    }

    public function test_a_user_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->authenticateAs('Admin');

        $this->putJson("/api/users/{$admin->id}", ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($admin->refresh()->is_active);
    }

    public function test_deactivating_a_user_revokes_their_tokens(): void
    {
        $this->authenticateAs('Admin');

        $user = User::factory()->create();
        $user->assignRole($this->role('Employé'));
        $user->createToken('api');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->putJson("/api/users/{$user->id}", ['is_active' => false])
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertFalse($user->refresh()->is_active);
    }

    public function test_a_deactivated_user_cannot_use_an_existing_token(): void
    {
        $user = User::factory()->create();
        $user->assignRole($this->role('Employé'));

        $token = $user->createToken('api')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/me')
            ->assertOk();

        $user->update(['is_active' => false]);

        // Chaque requete HTTP reelle repart d'un guard neuf : on reproduit ce
        // comportement dans le test, sinon Laravel reutilise l'utilisateur
        // authentifie de la requete precedente.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/me')
            ->assertForbidden();
    }
}
