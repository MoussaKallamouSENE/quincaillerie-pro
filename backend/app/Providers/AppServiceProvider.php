<?php

namespace App\Providers;

use App\Models\Refund;
use App\Models\User;
use App\Services\RefundDocumentService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Gestion des utilisateurs : reservee aux administrateurs et gerants,
        // ou a tout compte disposant explicitement de la permission.
        Gate::define('gerer_utilisateurs', function (User $user): bool {
            return $user->hasAnyRole(['Admin', 'Gérant'])
                || $user->getAllPermissions()->contains('name', 'gerer_utilisateurs');
        });

        $assertTerminalProofs = function (Refund $refund): void {
            if (! in_array($refund->status, [Refund::STATUS_EXECUTED, Refund::STATUS_FAILED_CONFIRMED], true)) {
                return;
            }

            app(RefundDocumentService::class)->assertProofsForStatus($refund);
        };

        Refund::updating(function (Refund $refund) use ($assertTerminalProofs): void {
            if (! $refund->isDirty('status')) {
                return;
            }

            $assertTerminalProofs($refund);
        });
    }
}
