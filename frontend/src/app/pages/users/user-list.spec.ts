import { HttpRequest, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { AuthService } from '../../core/auth/auth-service';
import type { AppUser, UsersPage } from '../../services/user.model';
import { UserList } from './user-list';

function buildUser(overrides: Partial<AppUser> = {}): AppUser {
  return {
    id: 2,
    name: 'Awa Diop',
    email: 'awa.diop@quincaillerie.com',
    phone: '+221 77 123 45 67',
    is_active: true,
    role: 'Employé',
    roles: ['Employé'],
    created_at: '2026-10-22T10:00:00+00:00',
    updated_at: '2026-10-22T10:00:00+00:00',
    ...overrides,
  };
}

function buildPage(data: AppUser[]): UsersPage {
  return {
    data,
    meta: {
      current_page: 1,
      from: data.length ? 1 : null,
      last_page: 1,
      per_page: 100,
      to: data.length ? data.length : null,
      total: data.length,
    },
  };
}

describe('UserList', () => {
  let fixture: ComponentFixture<UserList>;
  let httpMock: HttpTestingController;

  /** Compte connecte (utilise pour interdire l'auto-suppression). */
  const currentUser = signal<{ id: number } | null>({ id: 1 });

  /** Requete de la liste des utilisateurs. */
  const isUsersRequest = (request: HttpRequest<unknown>) => request.url.endsWith('/api/users');

  function element(): HTMLElement {
    return fixture.nativeElement as HTMLElement;
  }

  function buttonByLabel(label: string): HTMLButtonElement {
    return element().querySelector(`button[aria-label="${label}"]`) as HTMLButtonElement;
  }

  /** Saisit une valeur dans un champ du formulaire. */
  function setInput(selector: string, value: string): void {
    const input = element().querySelector(selector) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  /** Laisse les reponses HTTP et les signaux se propager. */
  async function settle(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0));
    fixture.detectChanges();
  }

  /** Repond a la liste des utilisateurs et stabilise la vue. */
  async function flushUsers(users: AppUser[]): Promise<void> {
    httpMock.expectOne(isUsersRequest).flush(buildPage(users));
    await settle();
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [UserList],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: AuthService, useValue: { user: currentUser.asReadonly() } },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(UserList);
    httpMock = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
  });

  afterEach(() => httpMock.verify());

  it('should list users with their role and status', async () => {
    await flushUsers([
      buildUser({
        id: 1,
        name: 'SuperAdmin',
        email: 'superadmin@quincaillerie.com',
        role: 'Admin',
        roles: ['Admin'],
      }),
      buildUser(),
    ]);

    const content = element().textContent ?? '';

    expect(content).toContain('SuperAdmin');
    expect(content).toContain('awa.diop@quincaillerie.com');
    expect(content).toContain('+221 77 123 45 67');
    expect(content).toContain('ADMIN');
    expect(content).toContain('EMPLOYÉ');
    expect(content).toContain('Actif');
  });

  it('should color the role badges by role', async () => {
    await flushUsers([
      buildUser({ id: 1, name: 'SuperAdmin', role: 'Admin', roles: ['Admin'] }),
      buildUser({ id: 3, name: 'Moussa Ndiaye', role: 'Gérant', roles: ['Gérant'] }),
    ]);

    const badges = Array.from(element().querySelectorAll('span')).filter((span) =>
      ['ADMIN', 'GÉRANT'].includes(span.textContent?.trim() ?? ''),
    );

    const adminBadge = badges.find((badge) => badge.textContent?.trim() === 'ADMIN');
    const managerBadge = badges.find((badge) => badge.textContent?.trim() === 'GÉRANT');

    expect(adminBadge?.className).toContain('bg-red-500/15');
    expect(managerBadge?.className).toContain('bg-blue-500/15');
  });

  it('should show an inactive status', async () => {
    await flushUsers([buildUser({ is_active: false })]);

    expect(element().textContent).toContain('Inactif');
  });

  it('should filter users as the user types', async () => {
    await flushUsers([
      buildUser(),
      buildUser({ id: 3, name: 'Moussa Ndiaye', email: 'moussa@quincaillerie.com' }),
    ]);

    const search = element().querySelector('input[type="search"]') as HTMLInputElement;
    search.value = 'moussa';
    search.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(element().querySelectorAll('tbody tr').length).toBe(1);
    expect(element().textContent).toContain('Moussa Ndiaye');
  });

  it('should create a user from the modal', async () => {
    await flushUsers([]);

    const createButton = Array.from(element().querySelectorAll('button')).find((button) =>
      button.textContent?.includes('Nouvel Utilisateur'),
    ) as HTMLButtonElement;

    createButton.click();
    fixture.detectChanges();

    setInput('#user-name', 'Awa Diop');
    setInput('#user-email', 'awa.diop@quincaillerie.com');
    setInput('#user-phone', '+221 77 123 45 67');
    setInput('#user-password', 'motdepasse');

    const form = element().querySelector('form') as HTMLFormElement;
    form.dispatchEvent(new Event('submit'));

    const request = httpMock.expectOne(
      (candidate) => candidate.url.endsWith('/api/users') && candidate.method === 'POST',
    );

    expect(request.request.body).toEqual({
      name: 'Awa Diop',
      email: 'awa.diop@quincaillerie.com',
      phone: '+221 77 123 45 67',
      role: 'Employé',
      is_active: true,
      password: 'motdepasse',
    });

    request.flush({ data: buildUser() });
    await settle();

    httpMock.expectOne(isUsersRequest).flush(buildPage([buildUser()]));
    await settle();

    expect(element().textContent).toContain('Awa Diop');
  });

  it('should update a user without sending an empty password', async () => {
    await flushUsers([buildUser()]);

    buttonByLabel('Modifier Awa Diop').click();
    fixture.detectChanges();

    setInput('#user-name', 'Awa Ndiaye');

    const form = element().querySelector('form') as HTMLFormElement;
    form.dispatchEvent(new Event('submit'));

    const request = httpMock.expectOne(
      (candidate) => candidate.url.endsWith('/api/users/2') && candidate.method === 'PUT',
    );

    expect(request.request.body['name']).toBe('Awa Ndiaye');
    expect(request.request.body['password']).toBeUndefined();
    expect(request.request.body['role']).toBe('Employé');

    request.flush({ data: buildUser({ name: 'Awa Ndiaye' }) });
    await settle();

    httpMock.expectOne(isUsersRequest).flush(buildPage([buildUser({ name: 'Awa Ndiaye' })]));
    await settle();

    expect(element().textContent).toContain('Awa Ndiaye');
  });

  it('should delete a user after confirmation', async () => {
    await flushUsers([buildUser()]);

    buttonByLabel('Supprimer Awa Diop').click();
    fixture.detectChanges();

    const confirm = Array.from(element().querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'Supprimer',
    ) as HTMLButtonElement;

    confirm.click();

    const request = httpMock.expectOne(
      (candidate) => candidate.url.endsWith('/api/users/2') && candidate.method === 'DELETE',
    );

    request.flush({ message: 'Utilisateur supprimé.' });
    await settle();

    httpMock.expectOne(isUsersRequest).flush(buildPage([]));
    await settle();

    expect(element().textContent).toContain('Aucun utilisateur enregistré');
  });

  it('should not allow deleting the current user', async () => {
    await flushUsers([buildUser({ id: 1, name: 'SuperAdmin', role: 'Admin', roles: ['Admin'] })]);

    expect(buttonByLabel('Supprimer SuperAdmin').disabled).toBe(true);
  });
});
