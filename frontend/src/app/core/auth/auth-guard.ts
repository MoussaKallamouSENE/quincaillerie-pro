import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthStore } from './auth-store';

/**
 * Protege les routes de l'application authentifiee.
 *
 * Redirige vers `/login` en conservant l'URL demandee dans `redirectUrl`, afin
 * de pouvoir y revenir apres connexion.
 *
 * ATTENTION : un guard client n'est pas une barriere de securite. La
 * verification des permissions doit toujours etre refaite cote serveur
 * (middleware `can:` / policies Laravel).
 */
export const authGuard: CanActivateFn = (_route, state) => {
  const store = inject(AuthStore);
  const router = inject(Router);

  if (store.isAuthenticated()) {
    return true;
  }

  return router.createUrlTree(['/login'], { queryParams: { redirectUrl: state.url } });
};

/**
 * Empeche d'afficher la page de connexion a un utilisateur deja connecte.
 */
export const guestGuard: CanActivateFn = () => {
  const store = inject(AuthStore);
  const router = inject(Router);

  return store.isAuthenticated() ? router.createUrlTree(['/']) : true;
};

/**
 * Restreint une route a certains roles.
 *
 * Usage : `{ path: 'users', canActivate: [roleGuard(['Admin'])] }`
 *
 * Le controle reel reste assure par le serveur.
 */
export function roleGuard(allowedRoles: string[]): CanActivateFn {
  return (_route, state) => {
    const store = inject(AuthStore);
    const router = inject(Router);

    if (store.hasRole(...allowedRoles)) {
      return true;
    }

    return router.createUrlTree(['/'], { queryParams: { denied: state.url } });
  };
}
