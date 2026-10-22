import { TestBed } from '@angular/core/testing';
import {
  ActivatedRouteSnapshot,
  Router,
  RouterStateSnapshot,
  UrlTree,
  provideRouter,
} from '@angular/router';
import { AuthApi } from './auth-api';
import { AuthService } from './auth-service';
import { authGuard, guestGuard, roleGuard } from './auth-guard';
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

    const allowed = TestBed.runInInjectionContext(() => roleGuard(['Admin'])(route, state));
    const denied = TestBed.runInInjectionContext(() => roleGuard(['Gerant'])(route, state));

    expect(allowed).toBe(true);
    expect(denied).toBeInstanceOf(UrlTree);
  });
});
