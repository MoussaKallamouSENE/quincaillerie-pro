import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';
import { AuthApi } from '../../../core/auth/auth-api';
import type { LoginResponse, MeResponse } from '../../../core/auth/auth.model';
import { Login } from './login';

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
    permissions: [],
  },
};

describe('Login', () => {
  let component: Login;
  let fixture: ComponentFixture<Login>;
  let api: {
    login: ReturnType<typeof vi.fn>;
    me: ReturnType<typeof vi.fn>;
    logout: ReturnType<typeof vi.fn>;
  };

  beforeEach(async () => {
    localStorage.clear();

    api = {
      login: vi.fn().mockReturnValue(of(LOGIN_RESPONSE)),
      me: vi.fn().mockReturnValue(of(ME_RESPONSE)),
      logout: vi.fn().mockReturnValue(of({ message: 'Deconnexion reussie.' })),
    };

    await TestBed.configureTestingModule({
      imports: [Login],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: AuthApi, useValue: api },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(Login);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  afterEach(() => localStorage.clear());

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should render the email and password fields', () => {
    const element = fixture.nativeElement as HTMLElement;

    expect(element.querySelector('input[type="email"]')).toBeTruthy();
    expect(element.querySelector('input[type="password"]')).toBeTruthy();
    expect(element.querySelector('button[type="submit"]')).toBeTruthy();
  });

  it('should show a validation message for an invalid email', async () => {
    component['model'].set({ email: 'pas-un-email', password: 'password' });
    await fixture.whenStable();

    component['onSubmit']();
    await fixture.whenStable();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('invalide');
  });

  it('should show the error message returned by the API', async () => {
    api.login.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 422,
            error: {
              message: 'The email field is required.',
              errors: { email: ['Les identifiants fournis sont incorrects.'] },
            },
          }),
      ),
    );

    component['model'].set({ email: 'superadmin@quincaillerie.com', password: 'mauvais' });
    await fixture.whenStable();

    component['onSubmit']();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await fixture.whenStable();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('[role="alert"]')?.textContent).toContain(
      'Les identifiants fournis sont incorrects.',
    );
  });

  it('should connect and redirect on success', async () => {
    component['model'].set({ email: 'superadmin@quincaillerie.com', password: 'password' });
    await fixture.whenStable();

    component['onSubmit']();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await fixture.whenStable();

    expect(api.login).toHaveBeenCalledWith({
      email: 'superadmin@quincaillerie.com',
      password: 'password',
    });
    expect(localStorage.getItem('quincaillerie.token')).toBe('token-abc');
  });
});
