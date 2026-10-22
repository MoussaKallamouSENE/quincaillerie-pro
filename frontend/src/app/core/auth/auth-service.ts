import { Service, computed, inject, signal } from '@angular/core';
import { Observable, firstValueFrom } from 'rxjs';
import { AuthApi } from './auth-api';
import { AuthUser, LoginCredentials, LoginResponse } from './auth.model';

/**
 * Cle de stockage du jeton d'authentification.
 *
 * NOTE SECURITE : le jeton est stocke dans `localStorage`, ce qui l'expose a
 * une eventuelle XSS. Le cookie `HttpOnly` serait plus sur mais impose
 * `supports_credentials => true` cote CORS, ce qui n'est pas possible ici
 * (le frontend et l'API sont sur des origines distinctes en developpement).
 * A arbitrer lors du passage en production.
 */
const TOKEN_STORAGE_KEY = 'quincaillerie.token';

/** Etat d'authentification expose a l'interface. */
export type AuthStatus = 'anonymous' | 'authenticating' | 'authenticated';

function readStoredToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_STORAGE_KEY);
  } catch {
    return null;
  }
}

function writeStoredToken(token: string): void {
  try {
    localStorage.setItem(TOKEN_STORAGE_KEY, token);
  } catch {
    // Stockage indisponible (navigation privee, quota) : la session reste
    // valable en memoire jusqu'au rechargement de la page.
  }
}

function removeStoredToken(): void {
  try {
    localStorage.removeItem(TOKEN_STORAGE_KEY);
  } catch {
    // Rien a faire.
  }
}

/**
 * Minuscule sans accents, pour comparer des roles ignores la casse.
 *
 * Les roles sont stockes avec leur libelle d'affichage (`Admin`, `Gérant`,
 * `Employé`) : cette normalisation permet d'appeler `hasRole('gerant')` sans
 * dependre des accents ou de la casse.
 */
function normalizeRole(role: string): string {
  return role
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '');
}

/** Reconstruit un `AuthUser` a partir de la reponse partielle de `/api/login`. */
function toAuthUser(partial: LoginResponse['user']): AuthUser {
  return {
    id: null,
    name: partial.name,
    email: partial.email,
    is_active: true,
    roles: partial.roles ?? [],
    permissions: [],
  };
}

/**
 * Service d'authentification : source de verite unique de l'application.
 *
 * L'intercepteur HTTP lit le jeton ici, les guards lisent
 * `isAuthenticated()` ici, et les composants lisent `user()`, `roles()` et
 * `permissions()`.
 */
@Service()
export class AuthService {
  private readonly api = inject(AuthApi);

  private readonly _token = signal<string | null>(readStoredToken());
  private readonly _user = signal<AuthUser | null>(null);
  private readonly _status = signal<AuthStatus>('anonymous');

  /** Jeton Bearer courant, injecte dans l'en-tete `Authorization`. */
  readonly token = this._token.asReadonly();

  /** Profil de l'utilisateur connecte, `null` si non connecte. */
  readonly user = this._user.asReadonly();

  /** Statut de l'authentification. */
  readonly status = this._status.asReadonly();

  /** Vrai des qu'un jeton est disponible. */
  readonly isAuthenticated = computed(() => this._token() !== null);

  /** Roles de l'utilisateur connecte (`Admin`, `Gérant`, `Employé`). */
  readonly roles = computed(() => this._user()?.roles ?? []);

  /** Permissions Spatie de l'utilisateur connecte. */
  readonly permissions = computed(() => this._user()?.permissions ?? []);

  /** Nom affiche, pour l'en-tete de l'application. */
  readonly displayName = computed(() => this._user()?.name ?? '');

  /** Initiales pour l'avatar de l'en-tete. */
  readonly initials = computed(() => {
    const name = this._user()?.name?.trim() ?? '';
    if (!name) {
      return '';
    }
    return name
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase())
      .join('');
  });

  /**
   * Vrai si l'utilisateur possede au moins un des roles donnes.
   *
   * La comparaison ignore la casse et les accents : `hasRole('gerant')`
   * reconnait le role `Gérant`.
   */
  hasRole(...roles: string[]): boolean {
    const currentRoles = this.roles().map(normalizeRole);

    return roles.some((role) => currentRoles.includes(normalizeRole(role)));
  }

  /** Vrai si l'utilisateur possede au moins une des permissions donnees. */
  hasPermission(...permissions: string[]): boolean {
    return permissions.some((permission) => this.permissions().includes(permission));
  }

  /**
   * Tente une connexion et emet la reponse brute de `POST /api/login`.
   *
   * L'echec est signale par le canal `error` de l'Observable : Laravel
   * repond en HTTP 422 avec `{ message, errors: { email: [message] } }`.
   *
   * Le jeton n'est PAS enregistre ici : c'est le role de `saveToken()`, ce qui
   * laisse au composant le controle de ce qu'il fait de la reponse.
   */
  login(email: string, password: string): Observable<LoginResponse> {
    this._status.set('authenticating');
    return this.api.login({ email, password });
  }

  /**
   * Enregistre le jeton renvoye par `login()`.
   *
   * Le jeton est ecrit dans `localStorage` : il sera relu au demarrage de
   * l'application et injecte par l'intercepteur HTTP dans l'en-tete
   * `Authorization`.
   */
  saveToken(token: string): void {
    this._token.set(token);
    writeStoredToken(token);
    this._status.set('authenticated');
  }

  /**
   * Connecte l'utilisateur et persiste le jeton.
   *
   * Variante complete de `login()` + `saveToken()` : apres
   * `POST /api/login`, on appelle `GET /api/me` pour obtenir le profil
   * complet (`id`, `is_active`, `permissions`). Si cet appel echoue, on
   * conserve le profil minimal renvoye par le login plutot que de faire
   * echouer la connexion.
   */
  async signIn(credentials: LoginCredentials): Promise<AuthUser> {
    this._status.set('authenticating');

    try {
      const response = await firstValueFrom(this.login(credentials.email, credentials.password));

      this.saveToken(response.token);

      const user = await this.refreshProfile().catch(() => toAuthUser(response.user));
      this._user.set(user);

      return user;
    } catch (error) {
      this.clear();
      throw error;
    }
  }

  /**
   * Recharge le profil depuis `GET /api/me`.
   *
   * A appeler au demarrage de l'application pour restaure une session
   * persistee (le jeton est dans `localStorage`, mais pas le profil).
   */
  async refreshProfile(): Promise<AuthUser> {
    const response = await firstValueFrom(this.api.me());
    this._user.set(response.user);
    return response.user;
  }

  /** Deconnecte l'utilisateur et purge le jeton local. */
  async signOut(): Promise<void> {
    try {
      await firstValueFrom(this.api.logout());
    } catch {
      // Le jeton distant n'a pas pu etre revoque : on purge quand meme le
      // jeton local pour ne pas laisser une session fantome active.
    } finally {
      this.clear();
    }
  }

  /** Purge l'etat local d'authentification. */
  clear(): void {
    this._token.set(null);
    this._user.set(null);
    this._status.set('anonymous');
    removeStoredToken();
  }
}
