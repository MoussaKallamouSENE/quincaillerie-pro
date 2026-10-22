/**
 * Contrats de l'API utilisateurs Laravel (`backend/routes/api.php`).
 *
 * Ces types reproduisent fidelement les reponses de `UserController` :
 * - `GET  /api/users`      -> 200 `{ data: AppUser[], meta: UsersMeta, links: ... }`
 * - `POST /api/users`      -> 201 `{ data: AppUser }`
 * - `PUT  /api/users/{id}` -> 200 `{ data: AppUser }`
 *
 * Les noms de champs conservent la casse `snake_case` du backend (convention
 * du projet). `role` est le premier role Spatie du compte, `roles` la liste
 * complete.
 */

/** Roles crees par `RoleAndPermissionSeeder`. */
export const USER_ROLES = ['Admin', 'Gérant', 'Employé'] as const;

/** Role proposé dans le formulaire utilisateur. */
export type UserRole = (typeof USER_ROLES)[number];

/** Compte utilisateur de la quincaillerie. */
export interface AppUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  is_active: boolean;
  /** Premier role du compte, `null` si aucun role n'est attribue. */
  role: string | null;
  /** Liste complete des roles Spatie. */
  roles: string[];
  created_at: string | null;
  updated_at: string | null;
}

/** Pagination renvoyee par Laravel dans `meta`. */
export interface UsersMeta {
  current_page: number;
  from: number | null;
  last_page: number;
  per_page: number;
  to: number | null;
  total: number;
}

/** Page d'utilisateurs renvoyee par `GET /api/users`. */
export interface UsersPage {
  data: AppUser[];
  meta: UsersMeta;
}

/** Utilisateur unique renvoye par l'API (enveloppe dans `data`). */
export interface UserResponse {
  data: AppUser;
}

/** Filtres acceptes par `GET /api/users`. */
export interface UserQuery {
  search?: string;
  role?: string;
  per_page?: number;
  page?: number;
}

/**
 * Donnees envoyees a `POST /api/users` (creation) ou `PUT /api/users/{id}`
 * (modification).
 *
 * `password` est obligatoire a la creation (8 caracteres minimum) et
 * facultatif a la modification : omis, le mot de passe actuel est conserve.
 */
export interface UserDraft {
  name: string;
  email: string;
  phone: string | null;
  password?: string;
  role: string;
  is_active?: boolean;
}
