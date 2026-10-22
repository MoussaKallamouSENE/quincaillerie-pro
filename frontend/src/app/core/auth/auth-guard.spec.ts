import { TestBed } from '@angular/core/testing';
import {
  ActivatedRouteSnapshot,
  Router,
  RouterStateSnapshot,
  UrlTree,
  provideRouter,
} from '@angular/router';
import { ToastService } from '../toast/toast-service';
import { AuthApi } from './auth-api';
import { AuthService } from './auth-service';
import { authGuard, guestGuard, permissionOrRoleGuard } from './auth-guard';
import { roleGuard } from './role.guard';
import { of } from 'rxjs';
import type { LoginResponse, MeResponse } from './auth.model';

const ME_RESPONSE: MeResponse = {
  user: {
    id: 1,
    name: 'SuperAdmin',
    email: 'superadmin@quincaillerie.com',
    is_active: true,
    roles: ['Admin'],
    permissions: [],
  },
};

const LOGIN_RESPONSE: LoginResponse = {
  token: 'token-abc',
  user: { name: 'SuperAdmin', email: 'superadmin@quincaillerie.com', roles: ['Admin'] },
};

describe('authGuard', () => {
  let store: AuthService;
  let router: Router;

  const route = {} as ActivatedRouteSnapshot;
  const state = { url: '/products' } as RouterStateSnapshot;

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        {
          provide: AuthApi,
          useValue: {
            login: vi.fn().mockReturnValue(of(LOGIN_RESPONSE)),
            me: vi.fn().mockReturnValue(of(ME_RESPONSE)),
            logout: vi.fn().mockReturnValue(of({ message: 'Deconnexion reussie.' })),
          },
        },
      ],
    });

    store = TestBed.inject(AuthService);
    router = TestBed.inject(Router);
  });

  afterEach(() => localStorage.clear());

  it('should redirect an anonymous user to /login', () => {
    const result = TestBed.runInInjectionContext(() => authGuard(route, state));

    expect(result).toBeInstanceOf(UrlTree);
    expect(router.serializeUrl(result as UrlTree)).toContain('/login');
    expect(router.serializeUrl(result as UrlTree)).toContain('redirectUrl');
  });

  it('should allow an authenticated user', async () => {
    await store.signIn({ email: 'a@b.c', password: 'x' });

    const result = TestBed.runInInjectionContext(() => authGuard(route, state));

    expect(result).toBe(true);
  });

  it('should redirect an authenticated user away from the login page', async () => {
    await store.signIn({ email: 'a@b.c', password: 'x' });

    const result = TestBed.runInInjectionContext(() => guestGuard(route, state));

    expect(result).toBeInstanceOf(UrlTree);
    expect(router.serializeUrl(result as UrlTree)).not.toContain('/login');
  });

  it('should allow an anonymous user on the login page', () => {
    const result = TestBed.runInInjectionContext(() => guestGuard(route, state));

    expect(result).toBe(true);
  });

  it('should enforce the allowed roles', async () => {
    await store.signIn({ email: 'a@b.c', password: 'x' });

    const allowed = await TestBed.runInInjectionContext(() => roleGuard('Admin')(route, state));
    const denied = await TestBed.runInInjectionContext(() => roleGuard('Gérant')(route, state));

    expect(allowed).toBe(true);
    expect(denied).toBeInstanceOf(UrlTree);
  });

  it('should compare roles regardless of case and accents', async () => {
    await store.signIn({ email: 'a@b.c', password: 'x' });

    const result = await TestBed.runInInjectionContext(() => roleGuard('admin')(route, state));

    expect(result).toBe(true);
  });

  it('should show an error message and redirect a user without the role', async () => {
    await store.signIn({ email: 'a@b.c', password: 'x' });
    const toastService = TestBed.inject(ToastService);

    const result = await TestBed.runInInjectionContext(() => roleGuard('Gérant')(route, state));

    expect(result).toBeInstanceOf(UrlTree);
    expect(router.serializeUrl(result as UrlTree)).toContain('/dashboard');
    expect(toastService.toasts()[0]?.message).toContain('Accès refusé');
  });

  it('should load the profile before checking a role', async () => {
    // Jeton restaure mais profil pas encore charge (rechargement direct).
    store.saveToken('token-abc');

    const result = await TestBed.runInInjectionContext(() => roleGuard('Admin')(route, state));

    expect(store.user()?.roles).toContain('Admin');
    expect(result).toBe(true);
  });

  it('should allow a user holding one of the required roles', async () => {
    await store.signIn({ email: 'a@b.c', password: 'x' });

    const result = await TestBed.runInInjectionContext(() =>
      permissionOrRoleGuard('gerer_utilisateurs', ['Admin', 'Gérant'])(route, state),
    );

    expect(result).toBe(true);
  });

  it('should redirect a user without the permission nor the role', async () => {
    await store.signIn({ email: 'a@b.c', password: 'x' });

    const result = await TestBed.runInInjectionContext(() =>
      permissionOrRoleGuard('gerer_utilisateurs', ['Employé'])(route, state),
    );

    expect(result).toBeInstanceOf(UrlTree);
    expect(router.serializeUrl(result as UrlTree)).toContain('/dashboard');
  });

  it('should load the profile before checking a restricted route', async () => {
    // Jeton restaure mais profil pas encore charge (rechargement direct).
    store.saveToken('token-abc');

    const result = await TestBed.runInInjectionContext(() =>
      permissionOrRoleGuard('gerer_utilisateurs', ['Admin'])(route, state),
    );

    expect(store.user()?.roles).toContain('Admin');
    expect(result).toBe(true);
  });
});
