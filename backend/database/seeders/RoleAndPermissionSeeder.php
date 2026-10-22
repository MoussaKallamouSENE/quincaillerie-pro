<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Le seeder est idempotent : les roles et l'utilisateur de demonstration
     * sont crees s'ils n'existent pas encore, puis mis a jour. Une seconde
     * execution ne doit pas echouer sur un doublon.
     */
    public function run(): void
    {
        // Créer les rôles
        $admin = Role::firstOrCreate(['name' => 'Admin']);
        $gerant = Role::firstOrCreate(['name' => 'Gérant']);
        $employe = Role::firstOrCreate(['name' => 'Employé']);

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
    }
}
