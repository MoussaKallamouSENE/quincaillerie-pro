import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import type { AppUser, UserDraft, UsersPage } from './user.model';
import { UserService } from './user-service';

const EMPTY_PAGE: UsersPage = {
  data: [],
  meta: {
    current_page: 1,
    from: null,
    last_page: 1,
    per_page: 15,
    to: null,
    total: 0,
  },
};

const USER: AppUser = {
  id: 4,
  name: 'Awa Diop',
  email: 'awa.diop@quincaillerie.com',
  phone: '+221 77 123 45 67',
  is_active: true,
  role: 'Employé',
  roles: ['Employé'],
  created_at: '2026-10-22T10:00:00+00:00',
  updated_at: '2026-10-22T10:00:00+00:00',
};

const DRAFT: UserDraft = {
  name: 'Awa Diop',
  email: 'awa.diop@quincaillerie.com',
  phone: '+221 77 123 45 67',
  password: 'motdepasse',
  role: 'Employé',
};

describe('UserService', () => {
  let service: UserService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(UserService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('should GET the users collection', () => {
    let received: UsersPage | undefined;

    service.getUsers().subscribe((page) => {
      received = page;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/users`);

    expect(request.request.method).toBe('GET');
    request.flush(EMPTY_PAGE);

    expect(received).toEqual(EMPTY_PAGE);
  });

  it('should forward the filters as query parameters', () => {
    service.getUsers({ search: 'awa', role: 'Employé', per_page: 100 }).subscribe();

    const request = httpMock.expectOne(
      (candidate) => candidate.url === `${environment.apiUrl}/users`,
    );

    expect(request.request.params.get('search')).toBe('awa');
    expect(request.request.params.get('role')).toBe('Employé');
    expect(request.request.params.get('per_page')).toBe('100');

    request.flush(EMPTY_PAGE);
  });

  it('should expose a request descriptor for httpResource', () => {
    const descriptor = service.listRequest({ per_page: 100 });

    expect(descriptor.url).toBe(`${environment.apiUrl}/users`);
    expect((descriptor.params as Record<string, unknown>)['per_page']).toBe(100);
  });

  it('should GET a single user', () => {
    let received: AppUser | undefined;

    service.getUser(4).subscribe((user) => {
      received = user;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/users/4`);

    expect(request.request.method).toBe('GET');

    // Laravel enveloppe l'utilisateur dans une cle `data`.
    request.flush({ data: USER });

    expect(received).toEqual(USER);
  });

  it('should POST the new user to the users collection', () => {
    let created: AppUser | undefined;

    service.createUser(DRAFT).subscribe((user) => {
      created = user;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/users`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(DRAFT);

    request.flush({ data: USER });

    expect(created).toEqual(USER);
  });

  it('should PUT the user changes on the user resource', () => {
    let updated: AppUser | undefined;

    service.updateUser(4, { name: 'Awa Ndiaye' }).subscribe((user) => {
      updated = user;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/users/4`);

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ name: 'Awa Ndiaye' });

    request.flush({ data: USER });

    expect(updated).toEqual(USER);
  });

  it('should DELETE the user resource', () => {
    let completed = false;

    service.deleteUser(4).subscribe(() => {
      completed = true;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/users/4`);

    expect(request.request.method).toBe('DELETE');

    request.flush({ message: 'Utilisateur supprimé.' });

    expect(completed).toBe(true);
  });
});
