import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import type { Product, ProductDraft, ProductsPage } from './product.model';
import { ProductService } from './product-service';

const EMPTY_PAGE: ProductsPage = {
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

const PRODUCT: Product = {
  id: 7,
  sku: 'PRD-0006',
  name: 'Marteau 500 g',
  category: { id: 1, name: 'Outillage' },
  unit: { id: 2, name: 'Pièce', symbol: 'pcs' },
  purchase_price: '3500.00',
  sale_price: '5500.00',
  stock_quantity: 120,
  min_stock_alert: 20,
  is_active: true,
  is_low_stock: false,
};

const DRAFT: ProductDraft = {
  name: 'Marteau 500 g',
  sku: 'PRD-0006',
  category_id: 1,
  unit_id: 2,
  purchase_price: 3500,
  sale_price: 5500,
  stock_quantity: 120,
  min_stock_alert: 20,
};

describe('ProductService', () => {
  let service: ProductService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(ProductService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('should GET the products collection', () => {
    let received: ProductsPage | undefined;

    service.getProducts().subscribe((page) => {
      received = page;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/products`);

    expect(request.request.method).toBe('GET');
    request.flush(EMPTY_PAGE);

    expect(received).toEqual(EMPTY_PAGE);
  });

  it('should forward the filters as query parameters', () => {
    service.getProducts({ search: 'perceuse', low_stock: true, per_page: 50 }).subscribe();

    const request = httpMock.expectOne(
      (candidate) => candidate.url === `${environment.apiUrl}/products`,
    );

    expect(request.request.params.get('search')).toBe('perceuse');
    expect(request.request.params.get('low_stock')).toBe('1');
    expect(request.request.params.get('per_page')).toBe('50');

    request.flush(EMPTY_PAGE);
  });

  it('should expose a request descriptor for httpResource', () => {
    const descriptor = service.listRequest({ per_page: 100 });

    expect(descriptor.url).toBe(`${environment.apiUrl}/products`);
    expect((descriptor.params as Record<string, unknown>)['per_page']).toBe(100);
  });

  it('should POST the new product to the products collection', () => {
    let created: Product | undefined;

    service.createProduct(DRAFT).subscribe((product) => {
      created = product;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/products`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(DRAFT);

    // Laravel enveloppe le produit cree dans une cle `data`.
    request.flush({ data: PRODUCT });

    expect(created).toEqual(PRODUCT);
  });
});
