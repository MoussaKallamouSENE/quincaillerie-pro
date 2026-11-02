import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import type { CategoryDraft, CategoryList, UnitList } from './reference.model';
import { ReferenceService } from './reference-service';

const CATEGORIES: CategoryList = {
  data: [
    {
      id: 1,
      name: 'Outillage',
      description: null,
      is_active: true,
      parent_id: null,
      parent: null,
    },
  ],
};

const UNITS: UnitList = {
  data: [{ id: 2, name: 'Pièce', symbol: 'pcs', description: null, is_active: true }],
};

describe('ReferenceService', () => {
  let service: ReferenceService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(ReferenceService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('should GET the categories collection', () => {
    let received: CategoryList | undefined;

    service.getCategories().subscribe((categories) => {
      received = categories;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/categories`);

    expect(request.request.method).toBe('GET');
    request.flush(CATEGORIES);

    expect(received).toEqual(CATEGORIES);
  });

  it('should GET the units collection', () => {
    let received: UnitList | undefined;

    service.getUnits().subscribe((units) => {
      received = units;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/units`);

    expect(request.request.method).toBe('GET');
    request.flush(UNITS);

    expect(received).toEqual(UNITS);
  });

  it('should expose the request descriptors for httpResource', () => {
    expect(service.categoriesRequest().url).toBe(`${environment.apiUrl}/categories`);
    expect(service.unitsRequest().url).toBe(`${environment.apiUrl}/units`);
  });

  it('should GET a single category', () => {
    let receivedName: string | undefined;

    service.getCategory(1).subscribe((response) => {
      receivedName = response.data.name;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/categories/1`);

    expect(request.request.method).toBe('GET');
    request.flush({ data: CATEGORIES.data[0] });

    expect(receivedName).toBe('Outillage');
  });

  it('should POST a new category', () => {
    const draft: CategoryDraft = {
      name: 'Plomberie',
      description: 'Tuyaux et raccords',
      parent_id: 1,
      is_active: true,
    };

    service.createCategory(draft).subscribe();

    const request = httpMock.expectOne(`${environment.apiUrl}/categories`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(draft);
    request.flush({ data: { ...CATEGORIES.data[0], name: 'Plomberie' } });
  });

  it('should PUT the category changes', () => {
    const draft: CategoryDraft = {
      name: 'Outillage à main',
      description: null,
      parent_id: null,
      is_active: false,
    };

    service.updateCategory(1, draft).subscribe();

    const request = httpMock.expectOne(`${environment.apiUrl}/categories/1`);

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual(draft);
    request.flush({ data: { ...CATEGORIES.data[0], ...draft } });
  });

  it('should DELETE an empty category', () => {
    let completed = false;

    service.deleteCategory(1).subscribe(() => {
      completed = true;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/categories/1`);

    expect(request.request.method).toBe('DELETE');
    request.flush({ message: 'Catégorie supprimée.' });

    expect(completed).toBe(true);
  });
});
