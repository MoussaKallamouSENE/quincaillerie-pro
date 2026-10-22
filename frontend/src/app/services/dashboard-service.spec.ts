import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import type { DashboardStats } from './dashboard.model';
import { DashboardService } from './dashboard-service';

const STATS: DashboardStats = {
  total_revenue_today: 141800,
  sales_count_today: 2,
  low_stock_count: 1,
  recent_sales: [
    {
      id: 2,
      reference: 'VTE-000002',
      customer_name: 'Boutique Chez Ali',
      total_amount: 1800,
      payment_status: 'paid',
      created_at: '2026-10-22T14:29:29+00:00',
    },
  ],
  sales_chart: [
    {
      date: '2026-10-22',
      label: 'jeu. 22',
      total_amount: 141800,
      sales_count: 2,
    },
  ],
};

describe('DashboardService', () => {
  let service: DashboardService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(DashboardService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('should GET the dashboard statistics', () => {
    let received: DashboardStats | undefined;

    service.getStats().subscribe((stats) => {
      received = stats;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/dashboard`);

    expect(request.request.method).toBe('GET');
    request.flush(STATS);

    expect(received).toEqual(STATS);
  });
});
