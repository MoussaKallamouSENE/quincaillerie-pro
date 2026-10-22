import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import type { Expense, ExpenseDraft, ExpensesPage } from './expense.model';
import { ExpenseService } from './expense-service';

const EXPENSE: Expense = {
  id: 4,
  cash_session_id: 7,
  user_id: 1,
  name: 'Transport livraison',
  amount: '1000.00',
  date: '2026-10-22',
  created_at: '2026-10-22T09:00:00+00:00',
  updated_at: '2026-10-22T09:00:00+00:00',
};

const PAGE: ExpensesPage = {
  data: [EXPENSE],
  meta: {
    cash_session_id: 7,
    count: 1,
    total: '1000.00',
  },
};

const DRAFT: ExpenseDraft = {
  name: 'Transport livraison',
  amount: 1000,
};

describe('ExpenseService', () => {
  let service: ExpenseService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(ExpenseService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('should GET the expenses of the current cash session', () => {
    let received: ExpensesPage | undefined;

    service.getExpenses().subscribe((page) => {
      received = page;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/expenses`);

    expect(request.request.method).toBe('GET');
    request.flush(PAGE);

    expect(received).toEqual(PAGE);
  });

  it('should POST the new expense and unwrap the created resource', () => {
    let created: Expense | undefined;

    service.createExpense(DRAFT).subscribe((expense) => {
      created = expense;
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/expenses`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(DRAFT);

    // Laravel enveloppe la depense dans une cle `data`.
    request.flush({ data: EXPENSE });

    expect(created).toEqual(EXPENSE);
  });

  it('should expose a request descriptor for httpResource', () => {
    const descriptor = service.listRequest();

    expect(descriptor.url).toBe(`${environment.apiUrl}/expenses`);
    expect(descriptor.method).toBeUndefined();
  });
});
