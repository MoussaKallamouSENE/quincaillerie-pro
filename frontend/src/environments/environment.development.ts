/**
 * Configuration de developpement.
 *
 * Les appels a l'API sont relatifs (`/api`) : le serveur de developpement
 * Angular les relaie vers Laravel (`http://127.0.0.1:8000`) via
 * `frontend/proxy.conf.json`. Le navigateur reste donc en same-origin et aucun
 * echange CORS n'est necessaire en developpement.
 *
 * `supports_credentials` est a `false` : l'authentification utilise un jeton
 * Bearer envoye dans l'en-tete `Authorization`, pas de cookie.
 */
export const environment = {
  production: false,
  apiUrl: '/api',
} as const;
