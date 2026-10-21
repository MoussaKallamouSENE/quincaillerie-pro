import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { AuthApi } from './auth-api';

describe('AuthApi', () => {
  let api: AuthApi;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    api = TestBed.inject(AuthApi);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('should be created', () => {
    expect(api).toBeTruthy();
  });

  it('should post the credentials to /login', () => {
    api.login({ email: 'superadmin@quincaillerie.com', password: 'password' }).subscribe();

    const req = httpMock.expectOne((r) => r.url.endsWith('/login'));
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({
      email: 'superadmin@quincaillerie.com',
      password: 'password',
    });

    req.flush({
      token: 'token-abc',
      user: { name: 'SuperAdmin', email: 'superadmin@quincaillerie.com', roles: ['Admin'] },
    });
  });

  it('should get the current profile from /me', () => {
    api.me().subscribe();

    const req = httpMock.expectOne((r) => r.url.endsWith('/me'));
    expect(req.request.method).toBe('GET');

    req.flush({
      user: {
        id: 1,
        name: 'SuperAdmin',
        email: 'superadmin@quincaillerie.com',
        is_active: true,
        roles: ['Admin'],
        permissions: [],
      },
    });
  });

  it('should post to /logout', () => {
    api.logout().subscribe();

    const req = httpMock.expectOne((r) => r.url.endsWith('/logout'));
    expect(req.request.method).toBe('POST');

    req.flush({ message: 'Deconnexion reussie.' });
  });
});
