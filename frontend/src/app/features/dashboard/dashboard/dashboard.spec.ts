import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { AuthApi } from '../../../core/auth/auth-api';
import type { LoginResponse, MeResponse } from '../../../core/auth/auth.model';
import { Dashboard } from './dashboard';

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

describe('Dashboard', () => {
  let fixture: ComponentFixture<Dashboard>;

  beforeEach(async () => {
    localStorage.clear();

    await TestBed.configureTestingModule({
      imports: [Dashboard],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
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
    }).compileComponents();

    fixture = TestBed.createComponent(Dashboard);
    await fixture.whenStable();
  });

  afterEach(() => localStorage.clear());

  it('should create', () => {
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('should render the dashboard title', () => {
    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Tableau de bord');
  });
});
