import { HttpErrorResponse } from '@angular/common/http';
import { ApiErrorBody } from './api-error.model';

/**
 * Extrait un message lisible a partir d'une `HttpErrorResponse`.
 *
 * Cas couverts :
 * - 422 Laravel : on privilegie le premier message de champ (`errors.email[0]`)
 *   car il est plus precis que le message generique.
 * - 0 (reseau indisponible / CORS bloque) : message explicite.
 * - autres : message du serveur, sinon message de repli.
 */
export function extractErrorMessage(error: unknown, fallback = 'Une erreur inattendue est survenue.'): string {
  if (!(error instanceof HttpErrorResponse)) {
    return fallback;
  }

  const body = error.error as ApiErrorBody | null;

  if (body?.errors) {
    const firstFieldMessages = Object.values(body.errors)[0];
    if (firstFieldMessages?.length) {
      return firstFieldMessages[0];
    }
  }

  if (body?.message) {
    return body.message;
  }

  if (error.status === 0) {
    return "Le serveur est injoignable. Verifiez que le backend est demarre sur http://localhost:8000.";
  }

  return fallback;
}
