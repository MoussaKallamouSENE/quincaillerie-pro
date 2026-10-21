/**
 * Configuration de developpement.
 *
 * Le backend Laravel tourne sur http://localhost:8000, le serveur Angular sur
 * http://localhost:4200. Les appels sont donc cross-origin : le CORS est
 * configure côté Laravel dans `backend/config/cors.php`
 * (`allowed_origins => ['http://localhost:4200']`).
 *
 * `supports_credentials` est a `false` : l'authentification utilise un jeton
 * Bearer envoye dans l'en-tete `Authorization`, pas de cookie.
 */
export const environment = {
  production: false,
  apiUrl: 'http://localhost:8000/api',
} as const;
