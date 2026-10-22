import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import type { Customer, CustomerDraft, CustomersPage } from './customer.model';
import { CustomerService } from './customer-service';

const EMPTY_PAGE: CustomersPage = {
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

const CUSTOMER: Customer = {
  id: 3,
  name: 'Client Passage',
  phone: '+221 77 512 34 56',
  email: 'contact@clientpassage.sn',
  address: 'Dakar Plateau',
  credit_limit: '500000.00',
  outstanding_balance: '0.00',
  is_active: true,
};

const DRAFT: CustomerDraft = {
  name: 'Client Passage',
  phone: '+221 77 512 34 56',
  email: 'contact@clientpassage.sn',
  address: 'Dakar Plateau',
  credit_limit: 500000,
};

describe('CustomerService', () => {
  let service: CustomerService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(CustomerService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('should GET the customers collection', () => {
    let received: CustomersPage | undefined;

    service.getCustomers().subscribe((page) => {
      received = page;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/customers`);

    expect(request.request.method).toBe('GET');
    request.flush(EMPTY_PAGE);

    expect(received).toEqual(EMPTY_PAGE);
  });

  it('should forward the filters as query parameters', () => {
    service.getCustomers({ search: 'ali', is_active: false, per_page: 50 }).subscribe();

    const request = httpMock.expectOne(
      (candidate) => candidate.url === `${environment.apiUrl}/customers`,
    );

    expect(request.request.params.get('search')).toBe('ali');
    expect(request.request.params.get('is_active')).toBe('0');
    expect(request.request.params.get('per_page')).toBe('50');

    request.flush(EMPTY_PAGE);
  });

  it('should expose a request descriptor for httpResource', () => {
    const descriptor = service.listRequest({ per_page: 100 });

    expect(descriptor.url).toBe(`${environment.apiUrl}/customers`);
    expect((descriptor.params as Record<string, unknown>)['per_page']).toBe(100);
  });

  it('should GET a single customer', () => {
    let received: Customer | undefined;

    service.getCustomer(3).subscribe((customer) => {
      received = customer;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/customers/3`);

    expect(request.request.method).toBe('GET');

    // Laravel enveloppe le client dans une cle `data`.
    request.flush({ data: CUSTOMER });

    expect(received).toEqual(CUSTOMER);
  });

  it('should POST the new customer to the customers collection', () => {
    let created: Customer | undefined;

    service.createCustomer(DRAFT).subscribe((customer) => {
      created = customer;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/customers`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(DRAFT);

    request.flush({ data: CUSTOMER });

    expect(created).toEqual(CUSTOMER);
  });

  it('should PUT the customer changes on the customer resource', () => {
    let updated: Customer | undefined;

    service.updateCustomer(3, DRAFT).subscribe((customer) => {
      updated = customer;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/customers/3`);

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual(DRAFT);

    request.flush({ data: CUSTOMER });

    expect(updated).toEqual(CUSTOMER);
  });

  it('should DELETE the customer resource', () => {
    let completed = false;

    service.deleteCustomer(3).subscribe(() => {
      completed = true;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/customers/3`);

    expect(request.request.method).toBe('DELETE');

    request.flush({ message: 'Client supprimé.' });

    expect(completed).toBe(true);
  });
});
