<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Créer les rôles
        $admin = Role::create(['name' => 'Admin']);
        $gerant = Role::create(['name' => 'Gérant']);
        $employe = Role::create(['name' => 'Employé']);

        // Créer le SuperAdmin user
        $superAdmin = User::create([
            'name' => 'SuperAdmin',
            'email' => 'superadmin@quincaillerie.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        // Assigner le rôle Admin au SuperAdmin
        $superAdmin->assignRole('Admin');
    }
}
