import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth-service';

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
  const auth = inject(AuthService);
  const router = inject(Router);

  if (auth.isAuthenticated()) {
    return true;
  }

  return router.createUrlTree(['/login'], { queryParams: { redirectUrl: state.url } });
};

/**
 * Empeche d'afficher la page de connexion a un utilisateur deja connecte.
 *
 * La redirection cible `/dashboard` et non `/` : la route par defaut redirige
 * elle-meme vers `/login`, ce qui provoquerait une boucle de redirection.
 */
export const guestGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  return auth.isAuthenticated() ? router.createUrlTree(['/dashboard']) : true;
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
    const auth = inject(AuthService);
    const router = inject(Router);

    if (auth.hasRole(...allowedRoles)) {
      return true;
    }

    return router.createUrlTree(['/dashboard'], { queryParams: { denied: state.url } });
  };
}

/**
 * Restreint une route a une permission Spatie ou a certains roles.
 *
 * Usage : `permissionOrRoleGuard('gerer_utilisateurs', ['Admin', 'Gérant'])`
 *
 * Contrairement a `roleGuard`, le guard attend le chargement du profil quand
 * l'utilisateur est authentifie mais que `GET /api/me` n'a pas encore repondu
 * (rechargement direct de la page) : sans cela, un administrateur serait
 * redirige a tort avant meme que ses roles soient connus.
 *
 * Le controle reel reste assure par le serveur.
 */
export function permissionOrRoleGuard(
  permission: string,
  allowedRoles: readonly string[],
): CanActivateFn {
  return async (_route, state) => {
    const auth = inject(AuthService);
    const router = inject(Router);

    if (auth.isAuthenticated() && !auth.user()) {
      await auth.refreshProfile().catch(() => undefined);
    }

    if (auth.hasPermission(permission) || auth.hasRole(...allowedRoles)) {
      return true;
    }

    return router.createUrlTree(['/dashboard'], { queryParams: { denied: state.url } });
  };
}
