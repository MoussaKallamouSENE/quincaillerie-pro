/**
 * Forme des reponses d'erreur renvoyees par l'API Laravel.
 *
 * Laravel renvoie deux structures distinctes :
 * - `ValidationException` (HTTP 422) : `{ message, errors: { champ: [msg] } }`
 * - autres erreurs : `{ message }` ou un corps vide.
 */
export interface ApiErrorBody {
  message?: string;
  errors?: Record<string, string[]>;
}
