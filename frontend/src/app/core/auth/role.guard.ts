import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { ToastService } from '../toast/toast-service';
import { AuthService } from './auth-service';

/**
 * Restreint une route aux roles donnes.
 *
 * Usage : `canActivate: [roleGuard('Admin', 'Gérant')]`
 *
 * Le profil est recharge avant la verification quand l'utilisateur est
 * authentifie mais que ses roles ne sont pas encore connus (rechargement
 * direct de la page) : sans cela, un administrateur serait redirige a tort.
 *
 * Un refus redirige vers le tableau de bord et affiche un message d'erreur.
 * Le controle reel reste assure par le serveur.
 */
export function roleGuard(...allowedRoles: string[]): CanActivateFn {
  return async (_route, state) => {
    const auth = inject(AuthService);
    const router = inject(Router);
    const toastService = inject(ToastService);

    if (auth.isAuthenticated() && !auth.user()) {
      await auth.refreshProfile().catch(() => undefined);
    }

    if (auth.hasRole(...allowedRoles)) {
      return true;
    }

    toastService.error(
      'Accès refusé : vous n’avez pas les droits nécessaires pour accéder à cette page.',
    );

    return router.createUrlTree(['/dashboard'], { queryParams: { denied: state.url } });
  };
}
