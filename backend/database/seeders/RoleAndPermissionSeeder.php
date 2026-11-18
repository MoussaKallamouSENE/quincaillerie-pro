<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Le seeder configure uniquement les rôles et permissions. Aucun compte
     * de démonstration ni mot de passe fixe n'est créé par défaut.
     */
    public function run(): void
    {
        // Créer les rôles
        $admin = Role::firstOrCreate(['name' => 'Admin']);
        $gerant = Role::firstOrCreate(['name' => 'Gérant']);
        $employe = Role::firstOrCreate(['name' => 'Employé']);

        // Permission de gestion des comptes : accordée aux profils qui peuvent
        // créer, modifier ou désactiver les utilisateurs.

        $gererUtilisateurs = Permission::firstOrCreate([
            'name' => 'gerer_utilisateurs',
            'guard_name' => 'web',
        ]);

        $ajusterStock = Permission::firstOrCreate([
            'name' => 'ajuster_stock',
            'guard_name' => 'web',
        ]);

        $demanderRemboursement = Permission::firstOrCreate([
            'name' => 'demander_remboursement',
            'guard_name' => 'web',
        ]);

        $approuverRemboursement = Permission::firstOrCreate([
            'name' => 'approuver_remboursement',
            'guard_name' => 'web',
        ]);

        $annulerRemboursement = Permission::firstOrCreate([
            'name' => 'annuler_remboursement',
            'guard_name' => 'web',
        ]);

        $executerRemboursement = Permission::firstOrCreate([
            'name' => 'executer_remboursement',
            'guard_name' => 'web',
        ]);

        $reconcilierRemboursement = Permission::firstOrCreate([
            'name' => 'reconcilier_remboursement',
            'guard_name' => 'web',
        ]);

        $admin->givePermissionTo($gererUtilisateurs);
        $gerant->givePermissionTo($gererUtilisateurs);

        $admin->givePermissionTo($ajusterStock);
        $gerant->givePermissionTo($ajusterStock);

        $admin->givePermissionTo([
            $demanderRemboursement,
            $approuverRemboursement,
            $annulerRemboursement,
            $reconcilierRemboursement,
        ]);

        $gerant->givePermissionTo([
            $demanderRemboursement,
            $approuverRemboursement,
            $annulerRemboursement,
            $executerRemboursement,
            $reconcilierRemboursement,
        ]);

        $employe->givePermissionTo([
            $demanderRemboursement,
            $executerRemboursement,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
