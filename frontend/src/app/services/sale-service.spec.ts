import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import type { ManualPaymentsResponse, Sale, SalePayload, SalesPage } from './sale.model';
import { SaleService } from './sale-service';

const SALE: Sale = {
  id: 12,
  reference: 'VTE-000012',
  customer_id: null,
  customer_name: null,
  user_id: 1,
  user_name: 'Vendeur',
  cash_session_id: 7,
  total_amount: '500.00',
  paid_amount: '500.00',
  pending_amount: '0.00',
  available_due: '0.00',
  balance_due: '0.00',
  payment_status: 'paid',
  items: [],
  payments: [],
  created_at: '2026-10-22T09:00:00+00:00',
  updated_at: '2026-10-22T09:00:00+00:00',
};

const PAGE: SalesPage = {
  data: [SALE],
  meta: {
    current_page: 1,
    from: 1,
    last_page: 1,
    per_page: 15,
    to: 1,
    total: 1,
  },
};

const MANUAL_PAYMENTS: ManualPaymentsResponse = {
  data: [
    {
      id: 44,
      sale_id: 12,
      sale_reference: 'VTE-000012',
      customer_name: null,
      seller_name: 'Vendeur',
      amount: '500.00',
      method: 'wave' as const,
      reference: 'WAVE-42',
      status: 'pending' as const,
      confirmed_at: null,
      confirmed_by_id: null,
      confirmed_by_name: null,
      rejected_at: null,
      rejected_by_id: null,
      rejected_by_name: null,
      rejection_reason: null,
      created_at: '2026-10-22T09:00:00+00:00',
      sale: {
        id: 12,
        reference: 'VTE-000012',
        total_amount: '500.00',
        paid_amount: '0.00',
        pending_amount: '500.00',
        available_due: '0.00',
        balance_due: '500.00',
        payment_status: 'credit' as const,
      },
    },
  ],
};

const PAYLOAD: SalePayload = {
  customer_id: null,
  items: [{ product_id: 1, quantity: 2 }],
  payment: { method: 'cash', amount: 500 },
};

describe('SaleService', () => {
  let service: SaleService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(SaleService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('should GET the paginated sales history', () => {
    let received: SalesPage | undefined;

    service.getSales({ payment_status: 'credit', per_page: 15 }).subscribe((page) => {
      received = page;
    });

    // La requete porte des filtres : l'URL est comparee sans la chaine de
    // requete, les filtres sont lus dans `params`.
    const request = httpMock.expectOne(
      (candidate) => candidate.url === `${environment.apiUrl}/sales`,
    );

    expect(request.request.method).toBe('GET');
    expect(request.request.params.get('payment_status')).toBe('credit');
    expect(request.request.params.get('per_page')).toBe('15');

    request.flush(PAGE);

    expect(received).toEqual(PAGE);
  });

  it('should GET a single sale by its identifier', () => {
    let received: Sale | undefined;

    service.getSale(12).subscribe((sale) => {
      received = sale;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/sales/12`);

    expect(request.request.method).toBe('GET');

    // Laravel enveloppe la vente dans une cle `data`.
    request.flush({ data: SALE });

    expect(received).toEqual(SALE);
  });

  it('should GET the invoice as a blob', () => {
    let received: Blob | undefined;

    service.downloadInvoice(12).subscribe((blob) => {
      received = blob;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/sales/12/invoice`);

    expect(request.request.method).toBe('GET');
    expect(request.request.responseType).toBe('blob');

    const pdf = new Blob(['%PDF-1.4'], { type: 'application/pdf' });
    request.flush(pdf);

    expect(received).toBe(pdf);
  });

  it('should expose a request descriptor for httpResource', () => {
    const descriptor = service.listRequest({ search: 'VTE-000012', page: 2 });

    expect(descriptor.url).toBe(`${environment.apiUrl}/sales`);
    expect((descriptor.params as Record<string, unknown>)['search']).toBe('VTE-000012');
    expect((descriptor.params as Record<string, unknown>)['page']).toBe(2);
  });

  it('should POST the sale to the sales collection', () => {
    let created: Sale | undefined;

    service.createSale(PAYLOAD).subscribe((sale) => {
      created = sale;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/sales`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(PAYLOAD);

    request.flush({ data: SALE });

    expect(created).toEqual(SALE);
  });

  it('should POST a credit sale without any payment', () => {
    const payload: SalePayload = {
      customer_id: 3,
      items: [{ product_id: 1, quantity: 1 }],
    };

    service.createSale(payload).subscribe();

    const request = httpMock.expectOne(`${environment.apiUrl}/sales`);

    expect(request.request.body).toEqual(payload);

    request.flush({ data: SALE });
  });

  it('should GET the manual payment verification queue', () => {
    let received: ManualPaymentsResponse | undefined;

    service.getManualPayments().subscribe((payments) => {
      received = payments;
    });

    const request = httpMock.expectOne(
      (candidate) => candidate.url === `${environment.apiUrl}/manual-payments`,
    );

    expect(request.request.method).toBe('GET');
    expect(request.request.params.get('status')).toBe('pending');

    request.flush(MANUAL_PAYMENTS);

    expect(received).toEqual(MANUAL_PAYMENTS);
  });

  it('should send the manual payment filters and pagination to the API', () => {
    service
      .getManualPayments({
        status: 'confirmed',
        method: 'orange_money',
        search: 'OM-42',
        page: 2,
        per_page: 10,
      })
      .subscribe();

    const request = httpMock.expectOne(
      (candidate) => candidate.url === `${environment.apiUrl}/manual-payments`,
    );

    expect(request.request.params.get('status')).toBe('confirmed');
    expect(request.request.params.get('method')).toBe('orange_money');
    expect(request.request.params.get('search')).toBe('OM-42');
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('per_page')).toBe('10');

    request.flush(MANUAL_PAYMENTS);
  });

  it('should expose a filtered and paginated request descriptor for the manual queue', () => {
    const descriptor = service.manualPaymentsRequest({
      status: 'rejected',
      method: 'wave',
      search: 'WAVE-42',
      page: 2,
      per_page: 10,
    });

    expect(descriptor.url).toBe(`${environment.apiUrl}/manual-payments`);
    expect(descriptor.params).toEqual({
      status: 'rejected',
      method: 'wave',
      search: 'WAVE-42',
      per_page: '10',
      page: '2',
    });
  });

  it('should POST the confirmation of a manual payment', () => {
    let updated: Sale | undefined;

    service.confirmManualPayment(44).subscribe((sale) => {
      updated = sale;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/manual-payments/44/confirm`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({});

    request.flush({ data: { ...SALE, paid_amount: '500.00', payment_status: 'paid' } });

    expect(updated?.payment_status).toBe('paid');
  });

  it('should POST the rejection of a manual payment with its reason', () => {
    let updated: Sale | undefined;

    service.rejectManualPayment(44, 'Référence introuvable').subscribe((sale) => {
      updated = sale;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/manual-payments/44/reject`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ reason: 'Référence introuvable' });

    request.flush({ data: SALE });

    expect(updated).toEqual(SALE);
  });

  it('should POST a later payment on a credit sale', () => {
    let updated: Sale | undefined;

    service
      .addPayment(12, { method: 'wave', amount: 200, reference: 'WAVE-42' })
      .subscribe((sale) => {
        updated = sale;
      });

    const request = httpMock.expectOne(`${environment.apiUrl}/sales/12/payments`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      method: 'wave',
      amount: 200,
      reference: 'WAVE-42',
    });

    request.flush({ data: { ...SALE, payment_status: 'partial', balance_due: '300.00' } });

    expect(updated?.payment_status).toBe('partial');
    expect(updated?.balance_due).toBe('300.00');
  });
});
