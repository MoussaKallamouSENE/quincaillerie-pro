<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class FixStockPermissions extends Command
{
    protected $signature = 'app:fix-stock-permissions';

    protected $description = 'Configure les permissions de gestion du stock';

    public function handle(): int
    {
        $admin = Role::findByName('Admin', 'web');

        // Gérant : le caractère é est écrit en Unicode
        $gerantName = "G\u{00E9}rant";

        $gerant = Role::query()
            ->where('name', $gerantName)
            ->where('guard_name', 'web')
            ->firstOrFail();

        $permission = Permission::firstOrCreate([
            'name' => 'ajuster_stock',
            'guard_name' => 'web',
        ]);

        $admin->givePermissionTo($permission);
        $gerant->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('Permission ajuster_stock attribuée à Admin et Gérant.');
        $this->info('Aucune permission supplémentaire attribuée à Employé.');

        return self::SUCCESS;
    }
}
