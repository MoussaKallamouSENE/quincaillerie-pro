import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import type { Purchase, PurchaseDraft } from './purchase-service';
import { PurchaseService } from './purchase-service';

const PURCHASE: Purchase = {
  id: 4,
  supplier_id: 2,
  user_id: 1,
  reference: 'ACH-ABCD1234',
  total_amount: '6000.00',
  status: 'pending',
  received_at: null,
  created_at: '2026-10-27T09:00:00+00:00',
  supplier: { id: 2, name: 'Cimenterie du Sahel' },
  items: [
    {
      id: 7,
      product_id: 3,
      quantity: 2,
      unit_price: 3000,
      subtotal: 6000,
      product: { id: 3, name: 'Ciment 50 kg' },
    },
  ],
};

const DRAFT: PurchaseDraft = {
  supplier_id: 2,
  items: [{ product_id: 3, quantity: 2, unit_price: 3000 }],
};

describe('PurchaseService', () => {
  let service: PurchaseService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(PurchaseService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('should GET the paginated purchases collection', () => {
    let received: unknown;

    service.getPurchases(2, 10).subscribe((page) => {
      received = page;
    });

    const request = httpMock.expectOne(
      (candidate) => candidate.url === `${environment.apiUrl}/purchases`,
    );

    expect(request.request.method).toBe('GET');
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('per_page')).toBe('10');

    request.flush({ data: [PURCHASE], meta: { current_page: 2 } });

    expect(received).toEqual({ data: [PURCHASE], meta: { current_page: 2 } });
  });

  it('should GET the detail of a purchase', () => {
    let received: Purchase | undefined;

    service.getPurchase(4).subscribe((purchase) => {
      received = purchase;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/purchases/4`);

    expect(request.request.method).toBe('GET');
    request.flush(PURCHASE);

    expect(received).toEqual(PURCHASE);
  });

  it('should POST a new purchase', () => {
    let created: Purchase | undefined;

    service.createPurchase(DRAFT).subscribe((purchase) => {
      created = purchase;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/purchases`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(DRAFT);
    request.flush(PURCHASE);

    expect(created).toEqual(PURCHASE);
  });

  it('should POST the reception of a purchase', () => {
    service.receivePurchase(4).subscribe();

    const request = httpMock.expectOne(`${environment.apiUrl}/purchases/4/receive`);

    expect(request.request.method).toBe('POST');
    request.flush({ message: 'Réception validée.', purchase: PURCHASE });
  });

  it('should POST the cancellation of a purchase', () => {
    let cancelled: Purchase | undefined;

    service.cancelPurchase(4).subscribe((response) => {
      cancelled = response.purchase;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/purchases/4/cancel`);

    expect(request.request.method).toBe('POST');
    request.flush({
      message: 'Commande annulée.',
      purchase: { ...PURCHASE, status: 'cancelled' },
    });

    expect(cancelled?.status).toBe('cancelled');
  });
});
