import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { AuthStore } from './auth-store';
import { authInterceptor, unauthorizedInterceptor } from './auth-interceptor';

describe('authInterceptor', () => {
  let http: HttpClient;
  let httpMock: HttpTestingController;
  let store: AuthStore;
  let router: { navigate: ReturnType<typeof vi.fn>; url: string };

  beforeEach(() => {
    localStorage.clear();
    // Le store relit le jeton depuis le localStorage a sa construction.
    localStorage.setItem('quincaillerie.token', 'token-xyz');

    router = { navigate: vi.fn(), url: '/products' };

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor, unauthorizedInterceptor])),
        provideHttpClientTesting(),
        { provide: Router, useValue: router },
      ],
    });

    http = TestBed.inject(HttpClient);
    httpMock = TestBed.inject(HttpTestingController);
    store = TestBed.inject(AuthStore);
  });

  afterEach(() => {
    httpMock.verify();
    localStorage.clear();
  });

  it('should attach the Bearer token to authenticated requests', () => {
    http.get('/api/products').subscribe();

    const req = httpMock.expectOne('/api/products');
    expect(req.request.headers.get('Authorization')).toBe('Bearer token-xyz');

    req.flush({});
  });

  it('should not attach the token to the login request', () => {
    http.post('/api/login', {}).subscribe();

    const req = httpMock.expectOne('/api/login');
    expect(req.request.headers.has('Authorization')).toBe(false);

    req.flush({});
  });

  it('should clear the session and redirect to /login on a 401', () => {
    http.get('/api/products').subscribe({ error: () => undefined });

    const req = httpMock.expectOne('/api/products');
    req.flush({ message: 'Unauthenticated.' }, { status: 401, statusText: 'Unauthorized' });

    expect(store.isAuthenticated()).toBe(false);
    expect(router.navigate).toHaveBeenCalledWith(['/login'], {
      queryParams: { redirectUrl: '/products' },
    });
  });

  it('should not redirect when the login request itself fails', () => {
    http.post('/api/login', {}).subscribe({ error: () => undefined });

    const req = httpMock.expectOne('/api/login');
    req.flush({ message: 'Erreur.' }, { status: 401, statusText: 'Unauthorized' });

    expect(router.navigate).not.toHaveBeenCalled();
  });
});
