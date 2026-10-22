import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import type { Supplier, SupplierDraft, SuppliersPage } from './supplier.model';
import { SupplierService } from './supplier-service';

const EMPTY_PAGE: SuppliersPage = {
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

const SUPPLIER: Supplier = {
  id: 1,
  name: 'Cimenterie du Sahel',
  contact_person: 'Amadou Fall',
  phone: '+221 77 333 22 11',
  email: 'contact@cimenterie-sahel.sn',
  address: 'Thiès, zone industrielle',
  is_active: true,
};

const DRAFT: SupplierDraft = {
  name: 'Cimenterie du Sahel',
  contact_person: 'Amadou Fall',
  phone: '+221 77 333 22 11',
  email: 'contact@cimenterie-sahel.sn',
  address: 'Thiès, zone industrielle',
};

describe('SupplierService', () => {
  let service: SupplierService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(SupplierService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('should GET the suppliers collection', () => {
    let received: SuppliersPage | undefined;

    service.getSuppliers().subscribe((page) => {
      received = page;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/suppliers`);

    expect(request.request.method).toBe('GET');
    request.flush(EMPTY_PAGE);

    expect(received).toEqual(EMPTY_PAGE);
  });

  it('should forward the filters as query parameters', () => {
    service.getSuppliers({ search: 'cimenterie', is_active: true, per_page: 50 }).subscribe();

    const request = httpMock.expectOne(
      (candidate) => candidate.url === `${environment.apiUrl}/suppliers`,
    );

    expect(request.request.params.get('search')).toBe('cimenterie');
    expect(request.request.params.get('is_active')).toBe('1');
    expect(request.request.params.get('per_page')).toBe('50');

    request.flush(EMPTY_PAGE);
  });

  it('should expose a request descriptor for httpResource', () => {
    const descriptor = service.listRequest({ per_page: 100 });

    expect(descriptor.url).toBe(`${environment.apiUrl}/suppliers`);
    expect((descriptor.params as Record<string, unknown>)['per_page']).toBe(100);
  });

  it('should GET a single supplier', () => {
    let received: Supplier | undefined;

    service.getSupplier(1).subscribe((supplier) => {
      received = supplier;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/suppliers/1`);

    expect(request.request.method).toBe('GET');

    // Laravel enveloppe le fournisseur dans une cle `data`.
    request.flush({ data: SUPPLIER });

    expect(received).toEqual(SUPPLIER);
  });

  it('should POST the new supplier to the suppliers collection', () => {
    let created: Supplier | undefined;

    service.createSupplier(DRAFT).subscribe((supplier) => {
      created = supplier;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/suppliers`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(DRAFT);

    request.flush({ data: SUPPLIER });

    expect(created).toEqual(SUPPLIER);
  });

  it('should PUT the supplier changes on the supplier resource', () => {
    let updated: Supplier | undefined;

    service.updateSupplier(1, DRAFT).subscribe((supplier) => {
      updated = supplier;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/suppliers/1`);

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual(DRAFT);

    request.flush({ data: SUPPLIER });

    expect(updated).toEqual(SUPPLIER);
  });

  it('should DELETE the supplier resource', () => {
    let completed = false;

    service.deleteSupplier(1).subscribe(() => {
      completed = true;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/suppliers/1`);

    expect(request.request.method).toBe('DELETE');

    request.flush({ message: 'Fournisseur supprimé.' });

    expect(completed).toBe(true);
  });
});
