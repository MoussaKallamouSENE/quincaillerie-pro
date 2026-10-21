import { TestBed } from '@angular/core/testing';
import { of, throwError } from 'rxjs';
import { AuthApi } from './auth-api';
import { AuthStore } from './auth-store';
import type { LoginResponse, MeResponse } from './auth.model';

const LOGIN_RESPONSE: LoginResponse = {
  token: 'token-abc',
  user: { name: 'SuperAdmin', email: 'superadmin@quincaillerie.com', roles: ['Admin'] },
};

const ME_RESPONSE: MeResponse = {
  user: {
    id: 1,
    name: 'SuperAdmin',
    email: 'superadmin@quincaillerie.com',
    is_active: true,
    roles: ['Admin'],
    permissions: ['products.view'],
  },
};

describe('AuthStore', () => {
  let store: AuthStore;
  let api: {
    login: ReturnType<typeof vi.fn>;
    me: ReturnType<typeof vi.fn>;
    logout: ReturnType<typeof vi.fn>;
  };

  beforeEach(() => {
    localStorage.clear();

    api = {
      login: vi.fn().mockReturnValue(of(LOGIN_RESPONSE)),
      me: vi.fn().mockReturnValue(of(ME_RESPONSE)),
      logout: vi.fn().mockReturnValue(of({ message: 'Deconnexion reussie.' })),
    };

    TestBed.configureTestingModule({
      providers: [AuthStore, { provide: AuthApi, useValue: api }],
    });

    store = TestBed.inject(AuthStore);
  });

  afterEach(() => localStorage.clear());

  it('should be created', () => {
    expect(store).toBeTruthy();
  });

  it('should start anonymous', () => {
    expect(store.isAuthenticated()).toBe(false);
    expect(store.user()).toBeNull();
    expect(store.roles()).toEqual([]);
  });

  it('should persist the token and load the full profile on sign in', async () => {
    const user = await store.signIn({
      email: 'superadmin@quincaillerie.com',
      password: 'password',
    });

    expect(api.login).toHaveBeenCalledWith({
      email: 'superadmin@quincaillerie.com',
      password: 'password',
    });
    expect(api.me).toHaveBeenCalled();
    expect(store.isAuthenticated()).toBe(true);
    expect(store.token()).toBe('token-abc');
    expect(localStorage.getItem('quincaillerie.token')).toBe('token-abc');
    expect(user.permissions).toEqual(['products.view']);
  });

  it('should keep the session usable when the profile call fails', async () => {
    api.me.mockReturnValue(throwError(() => new Error('offline')));

    const user = await store.signIn({ email: 'a@b.c', password: 'x' });

    expect(store.isAuthenticated()).toBe(true);
    expect(user.name).toBe('SuperAdmin');
    expect(user.id).toBeNull();
  });

  it('should clear the session when the login fails', async () => {
    api.login.mockReturnValue(throwError(() => new Error('invalid credentials')));

    await expect(store.signIn({ email: 'a@b.c', password: 'x' })).rejects.toThrow();

    expect(store.isAuthenticated()).toBe(false);
    expect(store.user()).toBeNull();
    expect(localStorage.getItem('quincaillerie.token')).toBeNull();
  });

  it('should clear the session on sign out', async () => {
    await store.signIn({ email: 'a@b.c', password: 'x' });
    await store.signOut();

    expect(api.logout).toHaveBeenCalled();
    expect(store.isAuthenticated()).toBe(false);
    expect(store.user()).toBeNull();
    expect(localStorage.getItem('quincaillerie.token')).toBeNull();
  });

  it('should expose roles and permissions', async () => {
    await store.signIn({ email: 'a@b.c', password: 'x' });

    expect(store.roles()).toEqual(['Admin']);
    expect(store.hasRole('Admin')).toBe(true);
    expect(store.hasRole('Gerant')).toBe(false);
    expect(store.hasPermission('products.view')).toBe(true);
    expect(store.hasPermission('sales.create')).toBe(false);
    expect(store.initials()).toBe('S');
  });
});
