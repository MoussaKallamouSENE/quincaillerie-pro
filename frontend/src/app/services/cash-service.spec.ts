import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import type { CashSession } from './cash.model';
import { CashService } from './cash-service';

const SESSION: CashSession = {
  id: 7,
  user_id: 1,
  opening_balance: '50000.00',
  closing_balance: null,
  status: 'open',
  opened_at: '2026-10-22T08:00:00+00:00',
  closed_at: null,
  expected_cash: '50000.00',
  cash_received: '0.00',
  expenses_total: '0.00',
  difference: null,
  opening_difference: null,
};

const CLOSED_SESSION: CashSession = {
  ...SESSION,
  closing_balance: '48000.00',
  status: 'closed',
  closed_at: '2026-10-22T18:00:00+00:00',
  cash_received: '5000.00',
  expected_cash: '55000.00',
  difference: '-7000.00',
  opening_difference: '-2000.00',
};

describe('CashService', () => {
  let service: CashService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(CashService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('should GET the current cash session', () => {
    let received: CashSession | null | undefined;

    service.getCurrentSession().subscribe((session) => {
      received = session;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/cash-sessions/current`);

    expect(request.request.method).toBe('GET');
    request.flush({ data: SESSION });

    expect(received).toEqual(SESSION);
  });

  it('should return null when no cash session is open', () => {
    let received: CashSession | null | undefined = SESSION;

    service.getCurrentSession().subscribe((session) => {
      received = session;
    });

    httpMock.expectOne(`${environment.apiUrl}/cash-sessions/current`).flush({ data: null });

    expect(received).toBeNull();
  });

  it('should POST the opening balance to open a cash session', () => {
    let opened: CashSession | undefined;

    service.openSession(50000).subscribe((session) => {
      opened = session;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/cash-sessions/open`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ opening_balance: 50000 });

    request.flush({ data: SESSION });

    expect(opened).toEqual(SESSION);
  });

  it('should POST the counted amount to close the cash session', () => {
    let closed: CashSession | undefined;

    service.closeSession(48000).subscribe((session) => {
      closed = session;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/cash-sessions/close`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ closing_balance: 48000 });

    request.flush({ data: CLOSED_SESSION });

    expect(closed).toEqual(CLOSED_SESSION);
  });

  it('should expose a request descriptor for httpResource', () => {
    const descriptor = service.currentRequest();

    expect(descriptor.url).toBe(`${environment.apiUrl}/cash-sessions/current`);
  });
});
