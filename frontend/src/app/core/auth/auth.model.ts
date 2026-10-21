/**
 * Contrats de l'API d'authentification Laravel (`backend/routes/api.php`).
 *
 * Ces types reproduisent fidelement les reponses de `AuthController` :
 * - `POST /api/login` -> 200 `{ token, user: { name, email, roles } }`
 * - `GET  /api/me`    -> 200 `{ user: { id, name, email, is_active, roles, permissions } }`
 */

/** Utilisateur authentifie, forme canonique issue de `GET /api/me`. */
export interface AuthUser {
  id: number | null;
  name: string;
  email: string;
  is_active: boolean;
  roles: string[];
  permissions: string[];
}

/** Identifiants envoyes a `POST /api/login`. */
export interface LoginCredentials {
  email: string;
  password: string;
}

/**
 * Reponse de `POST /api/login`.
 *
 * La sous-partie `user` est volontairement plus pauvre que `AuthUser` :
 * le backend n'y renvoie pas `id`, `is_active` ni `permissions`. C'est pourquoi
 * `AuthStore` appelle `GET /api/me` juste apres la connexion pour obtenir la
 * forme complete.
 */
export interface LoginResponse {
  token: string;
  user: {
    name: string;
    email: string;
    roles: string[];
  };
}

/** Reponse de `GET /api/me`. */
export interface MeResponse {
  user: AuthUser;
}

/** Reponse de `POST /api/logout`. */
export interface LogoutResponse {
  message: string;
}
