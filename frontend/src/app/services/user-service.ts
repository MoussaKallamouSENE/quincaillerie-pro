import { HttpClient, HttpResourceRequest } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { environment } from '../../environments/environment';
import { AppUser, UserDraft, UserQuery, UserResponse, UsersPage } from './user.model';

/**
 * Client HTTP de la gestion des utilisateurs.
 *
 * Les routes correspondent a `backend/routes/api.php` :
 * - `GET    /api/users`      (filtres `search`, `role`, `per_page`, `page`)
 * - `GET    /api/users/{id}`
 * - `POST   /api/users`
 * - `PUT    /api/users/{id}`
 * - `DELETE /api/users/{id}`
 *
 * L'API est protegee cote serveur : seuls les comptes Admin/Gerant ou porteurs
 * de la permission `gerer_utilisateurs` peuvent l'utiliser.
 *
 * L'URL de base vient de `environment.apiUrl` (`http://localhost:8000/api` en
 * developpement) : une seule variable a changer pour pointer ailleurs.
 */
@Service()
export class UserService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** URL de la collection utilisateurs. */
  readonly usersUrl = `${this.baseUrl}/users`;

  /**
   * Liste paginee des utilisateurs.
   *
   * @param query Filtres appliques par l'API (`search`, `role`, ...).
   */
  getUsers(query: UserQuery = {}): Observable<UsersPage> {
    return this.http.get<UsersPage>(this.usersUrl, { params: toParams(query) });
  }

  /**
   * Recupere un utilisateur par son identifiant.
   *
   * @param id Identifiant de l'utilisateur.
   */
  getUser(id: number): Observable<AppUser> {
    return this.http
      .get<UserResponse>(`${this.usersUrl}/${id}`)
      .pipe(map((response) => response.data));
  }

  /**
   * Cree un utilisateur.
   *
   * @param draft Donnees du nouveau compte (mot de passe obligatoire).
   */
  createUser(draft: UserDraft): Observable<AppUser> {
    return this.http
      .post<UserResponse>(this.usersUrl, draft)
      .pipe(map((response) => response.data));
  }

  /**
   * Met a jour un utilisateur.
   *
   * `password` peut etre omis pour conserver le mot de passe actuel.
   *
   * @param id Identifiant de l'utilisateur.
   * @param draft Champs a modifier.
   */
  updateUser(id: number, draft: Partial<UserDraft>): Observable<AppUser> {
    return this.http
      .put<UserResponse>(`${this.usersUrl}/${id}`, draft)
      .pipe(map((response) => response.data));
  }

  /**
   * Supprime un utilisateur.
   *
   * Le backend refuse la suppression de son propre compte et des comptes ayant
   * enregistre de l'activite (ventes, caisse, stock...).
   *
   * @param id Identifiant de l'utilisateur.
   */
  deleteUser(id: number): Observable<void> {
    return this.http.delete<void>(`${this.usersUrl}/${id}`);
  }

  /**
   * Descripteur de requete `GET /api/users`, consomme par `httpResource`.
   */
  listRequest(query: UserQuery = {}): HttpResourceRequest {
    return {
      url: this.usersUrl,
      params: toParams(query),
    };
  }
}

/** Convertit un filtre de recherche en parametres de requete HTTP. */
function toParams(query: UserQuery): Record<string, string | number> {
  const params: Record<string, string | number> = {};

  if (query.search) {
    params['search'] = query.search;
  }

  if (query.role) {
    params['role'] = query.role;
  }

  if (query.per_page !== undefined) {
    params['per_page'] = query.per_page;
  }

  if (query.page !== undefined) {
    params['page'] = query.page;
  }

  return params;
}
