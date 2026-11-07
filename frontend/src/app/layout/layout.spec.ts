import { computed, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { AuthService } from '../core/auth/auth-service';
import { Layout } from './layout';

describe('Layout', () => {
  let fixture: ComponentFixture<Layout>;

  /** Roles et permissions du faux utilisateur connecte. */
  const roles = signal<string[]>([]);
  const permissions = signal<string[]>([]);

  const authMock = {
    user: signal(null).asReadonly(),
    roles: roles.asReadonly(),
    permissions: permissions.asReadonly(),
    displayName: computed(() => ''),
    initials: computed(() => ''),
    isAuthenticated: computed(() => true),
    hasRole: (...allowed: string[]) => allowed.some((role) => roles().includes(role)),
    hasPermission: (...allowed: string[]) =>
      allowed.some((permission) => permissions().includes(permission)),
    refreshProfile: vi.fn().mockResolvedValue(undefined),
    signOut: vi.fn().mockResolvedValue(undefined),
  };

  /** Lien du menu vers la gestion des utilisateurs, s'il est affiche. */
  function usersLink(): HTMLAnchorElement | undefined {
    const element = fixture.nativeElement as HTMLElement;

    return Array.from(element.querySelectorAll('a[href]')).find(
      (link) => link.getAttribute('href') === '/users',
    ) as HTMLAnchorElement | undefined;
  }

  beforeEach(async () => {
    roles.set([]);
    permissions.set([]);

    await TestBed.configureTestingModule({
      imports: [Layout],
      providers: [provideRouter([]), { provide: AuthService, useValue: authMock }],
    }).compileComponents();

    fixture = TestBed.createComponent(Layout);
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('should link to the dashboard and to the products catalogue', () => {
    const element = fixture.nativeElement as HTMLElement;
    const links = Array.from(element.querySelectorAll('a[href]')) as HTMLAnchorElement[];

    expect(links.some((link) => link.getAttribute('href') === '/dashboard')).toBe(true);
    expect(links.some((link) => link.getAttribute('href') === '/products')).toBe(true);
  });

  it('should not render placeholder search or notification controls', () => {
    const element = fixture.nativeElement as HTMLElement;

    expect(element.querySelector('input[type="search"]')).toBeNull();
    expect(element.querySelector('[aria-label^="Notifications"]')).toBeNull();
  });

  it('should name the products entry in the navigation', () => {
    const element = fixture.nativeElement as HTMLElement;
    const link = Array.from(element.querySelectorAll('a[href]')).find(
      (candidate) => candidate.getAttribute('href') === '/products',
    );

    expect(link?.textContent).toContain('Produits');
  });

  it('should show the reports entry to an admin and a manager', () => {
    const reportsLink = () =>
      Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('a[href]')).find(
        (link) => link.getAttribute('href') === '/reports',
      );

    roles.set(['Admin']);
    fixture.detectChanges();
    expect(reportsLink()).toBeDefined();

    roles.set(['Gérant']);
    fixture.detectChanges();
    expect(reportsLink()).toBeDefined();
  });

  it('should hide the reports entry from an employee', () => {
    roles.set(['Employé']);
    fixture.detectChanges();

    const reportsLink = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('a[href]'),
    ).find((link) => link.getAttribute('href') === '/reports');

    expect(reportsLink).toBeUndefined();
  });

  it('should hide the users entry from an employee', () => {
    roles.set(['Employé']);
    fixture.detectChanges();

    expect(usersLink()).toBeUndefined();
  });

  it('should show the users entry to an admin', () => {
    roles.set(['Admin']);
    fixture.detectChanges();

    expect(usersLink()?.textContent).toContain('Utilisateurs');
  });

  it('should show the users entry to a manager', () => {
    roles.set(['Gérant']);
    fixture.detectChanges();

    expect(usersLink()).toBeDefined();
  });

  it('should hide the users entry from an employee even with the permission', () => {
    roles.set(['Employé']);
    permissions.set(['gerer_utilisateurs']);
    fixture.detectChanges();

    expect(usersLink()).toBeUndefined();
  });
});
