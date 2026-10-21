import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthStore } from './auth-store';

/**
 * Ajoute le jeton Bearer a toutes les requetes sortantes, sauf la connexion.
 *
 * Le jeton est lu depuis `AuthStore` a chaque requete : pas de copie locale,
 * donc pas de desynchronisation possible avec le store.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const store = inject(AuthStore);
  const token = store.token();

  if (!token || isLoginEndpoint(req.url)) {
    return next(req);
  }

  return next(req.clone({ setHeaders: { Authorization: `Bearer ${token}` } }));
};

/**
 * Repond aux reponses 401 en purgeant la session et en redirigeant vers la
 * page de connexion.
 *
 * L'erreur originale est toujours relayee a l'appelant : le composant qui a
 * declenche l'appel peut ainsi afficher un message contextualise.
 */
export const unauthorizedInterceptor: HttpInterceptorFn = (req, next) => {
  const store = inject(AuthStore);
  const router = inject(Router);

  return next(req).pipe(
    catchError((error: unknown) => {
      if (error instanceof HttpErrorResponse && error.status === 401 && !isLoginEndpoint(req.url)) {
        store.clear();
        void router.navigate(['/login'], { queryParams: { redirectUrl: router.url } });
      }

      return throwError(() => error);
    }),
  );
};

/** `/api/login` ne doit jamais porter de jeton ni declencher de redirection. */
function isLoginEndpoint(url: string): boolean {
  return url.endsWith('/login');
}
