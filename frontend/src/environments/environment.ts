/**
 * Configuration de production.
 *
 * L'API est servie sur la même origine que le frontend (reverse proxy nginx),
 * ce qui évite toute requête cross-origin. En developpement, voir
 * `environment.development.ts`.
 */
export const environment = {
  production: true,
  apiUrl: '/api',
} as const;
