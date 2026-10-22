<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Le seeder est idempotent : les roles, la permission et l'utilisateur de
     * demonstration sont crees s'ils n'existent pas encore. Une seconde
     * execution ne doit pas echouer sur un doublon.
     */
    public function run(): void
    {
        // Créer les rôles
        $admin = Role::firstOrCreate(['name' => 'Admin']);
        $gerant = Role::firstOrCreate(['name' => 'Gérant']);
        $employe = Role::firstOrCreate(['name' => 'Employé']);

        // Permission de gestion des comptes : accordée aux profils qui peuvent
        // créer, modifier ou désactiver les utilisateurs.
        $gererUtilisateurs = Permission::firstOrCreate(['name' => 'gerer_utilisateurs']);
        $admin->givePermissionTo($gererUtilisateurs);
        $gerant->givePermissionTo($gererUtilisateurs);

        // Créer le SuperAdmin user
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@quincaillerie.com'],
            [
                'name' => 'SuperAdmin',
                'password' => bcrypt('password'),
                'is_active' => true,
            ],
        );

        // Assigner le rôle Admin au SuperAdmin
        $superAdmin->assignRole($admin);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
