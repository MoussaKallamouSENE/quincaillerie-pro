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

        $this->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/users', $this->payload())->assertForbidden();
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
}
