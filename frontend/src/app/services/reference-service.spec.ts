import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import type { CategoryList, UnitList } from './reference.model';
import { ReferenceService } from './reference-service';

const CATEGORIES: CategoryList = {
  data: [{ id: 1, name: 'Outillage', description: null, is_active: true }],
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
});
