import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import {
  ReportService,
  type ReportPage,
  type ReportSummary,
} from './report-service';

const SUMMARY: ReportSummary = {
  period: {
    from: '2026-11-01',
    to: '2026-11-03',
  },
  currency: 'XOF',
  metrics: {
    revenue: 1250000,
    sales_count: 45,
    received_purchases: 350000,
    received_purchases_count: 8,
    expenses: 75000,
    profit: null,
    margin: null,
  },
  activity: [
    {
      bucket: '2026-11-03',
      amount: 125000,
      count: 5,
    },
  ],
  top_products: [
    {
      id: 1,
      name: 'Ciment',
      sku: 'CIM-001',
      quantity: 20,
      revenue: 150000,
    },
  ],
  notes: [],
};

const REPORT_PAGE: ReportPage = {
  type: 'sales',
  data: [
    {
      id: 12,
      reference: 'VTE-000012',
      total_amount: 50000,
    },
  ],
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 1,
  },
};

describe('ReportService', () => {
  let service: ReportService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(ReportService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('should GET the report summary with the selected period', () => {
    let received: ReportSummary | undefined;

    service.summary({ period: 'month' }).subscribe((summary) => {
      received = summary;
    });

    const request = httpMock.expectOne(
      (candidate) => candidate.url === `${environment.apiUrl}/reports/summary`,
    );

    expect(request.request.method).toBe('GET');
    expect(request.request.params.get('period')).toBe('month');
    expect(request.request.params.get('per_page')).toBe('25');

    request.flush(SUMMARY);

    expect(received).toEqual(SUMMARY);
  });

  it('should GET a report with all supplied filters', () => {
    let received: ReportPage | undefined;

    service
      .report('sales', {
        period: 'custom',
        from: '2026-11-01',
        to: '2026-11-03',
        search: 'VTE-000012',
        page: 2,
        per_page: 15,
      })
      .subscribe((page) => {
        received = page;
      });

    const request = httpMock.expectOne(
      (candidate) => candidate.url === `${environment.apiUrl}/reports/sales`,
    );

    expect(request.request.method).toBe('GET');
    expect(request.request.params.get('period')).toBe('custom');
    expect(request.request.params.get('from')).toBe('2026-11-01');
    expect(request.request.params.get('to')).toBe('2026-11-03');
    expect(request.request.params.get('search')).toBe('VTE-000012');
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('per_page')).toBe('15');

    request.flush(REPORT_PAGE);

    expect(received).toEqual(REPORT_PAGE);
  });

  it('should use the default page size when no per_page is supplied', () => {
    service.report('stock', { period: 'today' }).subscribe();

    const request = httpMock.expectOne(
      (candidate) => candidate.url === `${environment.apiUrl}/reports/stock`,
    );

    expect(request.request.params.get('period')).toBe('today');
    expect(request.request.params.get('per_page')).toBe('25');
    expect(request.request.params.has('from')).toBe(false);
    expect(request.request.params.has('to')).toBe(false);
    expect(request.request.params.has('search')).toBe(false);
    expect(request.request.params.has('page')).toBe(false);

    request.flush({
      type: 'stock',
      data: [],
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 0,
      },
    });
  });

  it('should GET the report PDF as a blob with the supplied filters', () => {
    let received: Blob | undefined;

    service
      .pdf('sales', {
        period: 'custom',
        from: '2026-11-01',
        to: '2026-11-03',
      })
      .subscribe((blob) => {
        received = blob;
      });

    const request = httpMock.expectOne(
      (candidate) => candidate.url === `${environment.apiUrl}/reports/sales/pdf`,
    );

    expect(request.request.method).toBe('GET');
    expect(request.request.responseType).toBe('blob');
    expect(request.request.params.get('period')).toBe('custom');
    expect(request.request.params.get('from')).toBe('2026-11-01');
    expect(request.request.params.get('to')).toBe('2026-11-03');

    const pdf = new Blob(['%PDF-1.4'], {
      type: 'application/pdf',
    });

    request.flush(pdf);

    expect(received).toBe(pdf);
  });
});
