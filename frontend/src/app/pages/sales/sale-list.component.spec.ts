import { registerLocaleData } from '@angular/common';
import localeFr from '@angular/common/locales/fr';
import { provideHttpClient, HttpRequest } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { LOCALE_ID } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { ToastService } from '../../core/toast/toast-service';
import type {
  ManualPayment,
  ManualPaymentsResponse,
  Sale,
  SalePayment,
  SalesMeta,
  SalesPage,
} from '../../services/sale.model';
import { SaleListComponent } from './sale-list.component';

// L'application s'execute en francais (`app.config.ts`) : les specs doivent
// reproduire cette locale pour que les montants soient formates pareil.
registerLocaleData(localeFr);

/**
 * Remplace les espaces typographiques par une espace normale.
 *
 * En locale francaise, le separateur de milliers est une espace fine
 * insecable (U+202F) : sans cette normalisation, les montants formates ne
 * sont pas comparables a une chaine ecrite a la main.
 */
function normalizeSpaces(text: string): string {
  return text.replace(/[\u00a0\u202f]/g, ' ');
}

function buildSale(overrides: Partial<Sale> = {}): Sale {
  return {
    id: 12,
    reference: 'VTE-000012',
    customer_id: null,
    customer_name: null,
    user_id: 1,
    user_name: 'SuperAdmin',
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
    ...overrides,
  };
}

function buildPayment(overrides: Partial<SalePayment> = {}): SalePayment {
  return {
    id: 1,
    amount: '500.00',
    method: 'cash',
    reference: null,
    status: 'confirmed',
    confirmed_at: '2026-10-22T09:00:00+00:00',
    confirmed_by_id: 1,
    confirmed_by_name: 'SuperAdmin',
    rejected_at: null,
    rejected_by_id: null,
    rejected_by_name: null,
    rejection_reason: null,
    created_at: '2026-10-22T09:00:00+00:00',
    ...overrides,
  };
}

function buildManualPayment(overrides: Partial<ManualPayment> = {}): ManualPayment {
  return {
    id: 44,
    sale_id: 12,
    sale_reference: 'VTE-000012',
    customer_name: null,
    seller_name: 'SuperAdmin',
    amount: '500.00',
    method: 'wave',
    reference: 'WAVE-42',
    status: 'pending',
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
      payment_status: 'credit',
    },
    ...overrides,
  };
}

function buildPage(data: Sale[], overrides: Partial<SalesPage['meta']> = {}): SalesPage {
  return {
    data,
    meta: {
      current_page: 1,
      from: data.length ? 1 : null,
      last_page: 1,
      per_page: 15,
      to: data.length ? data.length : null,
      total: data.length,
      ...overrides,
    },
  };
}

function buildManualPage(
  data: ManualPayment[],
  overrides: Partial<SalesMeta> = {},
): ManualPaymentsResponse {
  return {
    data,
    meta: {
      current_page: 1,
      from: data.length ? 1 : null,
      last_page: 1,
      per_page: 10,
      to: data.length ? data.length : null,
      total: data.length,
      ...overrides,
    },
  };
}

describe('SaleListComponent', () => {
  let fixture: ComponentFixture<SaleListComponent>;
  let httpMock: HttpTestingController;
  let toastService: ToastService;

  /** Requete de l'historique des ventes. */
  const isSalesRequest = (request: HttpRequest<unknown>) => request.url.endsWith('/api/sales');

  /** Requete de la file des paiements manuels a verifier. */
  const isManualPaymentsRequest = (request: HttpRequest<unknown>) =>
    request.url.endsWith('/api/manual-payments');

  /** Requete dediee au total global des paiements en attente. */
  const isManualPendingCountRequest = (request: HttpRequest<unknown>) =>
    isManualPaymentsRequest(request) &&
    request.params.get('status') === 'pending' &&
    request.params.get('page') === '1' &&
    request.params.get('per_page') === '1';

  /** Requete de la liste paginee des paiements manuels. */
  const isManualListRequest = (request: HttpRequest<unknown>) =>
    isManualPaymentsRequest(request) && !isManualPendingCountRequest(request);

  /** Requete d'ajout d'un reglement a une vente. */
  const isAddPaymentRequest = (request: HttpRequest<unknown>) =>
    request.url.endsWith('/api/sales/12/payments') && request.method === 'POST';

  /**
   * Laisse les reponses HTTP et les signaux se propager.
   *
   * `fixture.whenStable()` ne peut pas etre utilise ici : les ressources
   * `httpResource` laissent des requetes en attente, qui bloqueraient
   * l'attente.
   */
  async function settle(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0));
    fixture.detectChanges();
  }

  /** Repond a l'historique des ventes et stabilise la vue. */
  async function flushSales(
    sales: Sale[],
    overrides: Partial<SalesPage['meta']> = {},
    manualPayments: ManualPayment[] = [],
    manualMeta: Partial<SalesMeta> = {},
    pendingTotal = manualPayments.filter((payment) => payment.status === 'pending').length,
  ): Promise<void> {
    httpMock.expectOne(isSalesRequest).flush(buildPage(sales, overrides));
    await settle();

    flushManualPageRequests(manualPayments, manualMeta, pendingTotal);
    await settle();
  }

  /** Repond aux requetes de liste et de total global de la file manuelle. */
  function flushManualPageRequests(
    manualPayments: ManualPayment[],
    manualMeta: Partial<SalesMeta> = {},
    pendingTotal = manualPayments.filter((payment) => payment.status === 'pending').length,
  ): void {
    httpMock
      .match(isManualListRequest)
      .forEach((request) => request.flush(buildManualPage(manualPayments, manualMeta)));

    httpMock
      .match(isManualPendingCountRequest)
      .forEach((request) =>
        request.flush(buildManualPage([], { per_page: 1, total: pendingTotal })),
      );
  }

  /** Repond a une eventuelle requete de rechargement de la file manuelle. */
  async function flushManualPayments(
    manualPayments: ManualPayment[] = [],
    manualMeta: Partial<SalesMeta> = {},
    pendingTotal?: number,
  ): Promise<void> {
    flushManualPageRequests(manualPayments, manualMeta, pendingTotal);
    await settle();
  }

  /** Saisit un terme dans la barre de recherche de l'historique. */
  function search(term: string): void {
    setField('#sales-search', term);
  }

  /** Saisit une reference dans la recherche de la file manuelle. */
  function searchManualPayments(term: string): void {
    setField('#manual-payment-search', term);
  }

  /** Change le filtre de statut de reglement. */
  function filterBy(status: string): void {
    const select = (fixture.nativeElement as HTMLElement).querySelector(
      '#sales-status',
    ) as HTMLSelectElement;

    select.value = status;
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();
  }

  /** Lignes du tableau des ventes. */
  function rows(): HTMLTableRowElement[] {
    const table = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-testid="sales-history-table"]',
    );

    return Array.from(table?.querySelectorAll('tbody tr') ?? []) as HTMLTableRowElement[];
  }

  /** Lignes de la file des paiements manuels. */
  function manualPaymentRows(): HTMLTableRowElement[] {
    const table = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-testid="manual-payments-table"]',
    );

    return Array.from(table?.querySelectorAll('tbody tr') ?? []) as HTMLTableRowElement[];
  }

  /** Clique sur le premier bouton dont le libelle commence par le texte donne. */
  function clickButtonStartingWith(text: string): void {
    const button = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button'),
    ).find((candidate) => candidate.textContent?.trim().startsWith(text));

    button?.click();
    fixture.detectChanges();
  }

  /** Clique sur le bouton dont le libelle exact est donne. */
  function clickButton(text: string): void {
    const button = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button'),
    ).find((candidate) => candidate.textContent?.trim() === text);

    button?.click();
    fixture.detectChanges();
  }

  /** Clique sur un bouton de pagination de la file manuelle. */
  function clickManualPaginationButton(text: string): void {
    const footer = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-testid="manual-payments-pagination"]',
    );
    const button = Array.from(footer?.querySelectorAll('button') ?? []).find(
      (candidate) => candidate.textContent?.trim() === text,
    );

    button?.click();
    fixture.detectChanges();
  }

  /** Saisit une valeur dans un champ du document. */
  function setField(selector: string, value: string): void {
    const input = (fixture.nativeElement as HTMLElement).querySelector(selector) as
      HTMLInputElement | HTMLTextAreaElement;

    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  /** Selectionne une valeur dans une liste deroulante. */
  function selectValue(selector: string, value: string): void {
    const select = (fixture.nativeElement as HTMLElement).querySelector(
      selector,
    ) as HTMLSelectElement;

    select.value = value;
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();
  }

  /** Modale de detail, si elle est ouverte. */
  function dialog(): HTMLElement | null {
    return (fixture.nativeElement as HTMLElement).querySelector('[role="dialog"]');
  }

  /** Clique sur le bouton "Voir" de la premiere ligne. */
  function openFirstDetails(): void {
    const button = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button'),
    ).find((candidate) => candidate.textContent?.trim() === 'Voir');

    button?.click();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [SaleListComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: LOCALE_ID, useValue: 'fr-FR' },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(SaleListComponent);
    httpMock = TestBed.inject(HttpTestingController);
    toastService = TestBed.inject(ToastService);

    // Rendu initial et execution des effets : la requete de l'historique est
    // emise par la ressource de la page.
    fixture.detectChanges();
  });

  afterEach(() => {
    for (const request of httpMock.match(isSalesRequest)) {
      request.flush(buildPage([]));
    }

    for (const request of httpMock.match(isManualListRequest)) {
      request.flush(buildManualPage([]));
    }

    for (const request of httpMock.match(isManualPendingCountRequest)) {
      request.flush(buildManualPage([], { per_page: 1, total: 0 }));
    }
  });

  it('should create', () => {
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('should announce the loading of the history', () => {
    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Chargement de l’historique des ventes');
  });

  it('should render the sales returned by the API', async () => {
    await flushSales([
      buildSale(),
      buildSale({
        id: 13,
        reference: 'VTE-000013',
        customer_id: 3,
        customer_name: 'Client Passage',
        user_name: 'Moussa Diop',
        total_amount: '15000.00',
        paid_amount: '5000.00',
        balance_due: '10000.00',
        payment_status: 'partial',
      }),
    ]);

    const element = fixture.nativeElement as HTMLElement;
    const text = normalizeSpaces(element.textContent ?? '');

    expect(rows()).toHaveLength(2);
    expect(text).toContain('VTE-000012');
    expect(text).toContain('VTE-000013');
    expect(text).toContain('Client Passage');
    expect(text).toContain('Moussa Diop');
    expect(text).toContain('500,00');
    expect(text).toContain('15 000,00');
  });

  it('should display "Passage" when the sale has no customer', async () => {
    await flushSales([buildSale({ customer_name: null })]);

    const [row] = rows();

    expect(row.textContent).toContain('Passage');
  });

  it('should display the payment status of each sale', async () => {
    await flushSales([
      buildSale({ id: 1, payment_status: 'paid' }),
      buildSale({ id: 2, payment_status: 'partial' }),
      buildSale({ id: 3, payment_status: 'credit' }),
    ]);

    const text = normalizeSpaces((fixture.nativeElement as HTMLElement).textContent ?? '');

    expect(text).toContain('Payé');
    expect(text).toContain('Partiel');
    expect(text).toContain('Crédit');
  });

  it('should open the details of a sale in a modal', async () => {
    await flushSales([
      buildSale({
        items: [
          {
            id: 1,
            product_id: 33,
            product_name: 'Marteau 500 g',
            quantity: 2,
            unit_price: '5500.00',
            subtotal: '11000.00',
          },
        ],
        payments: [buildPayment({ id: 1, amount: '11000.00' })],
      }),
    ]);

    expect(dialog()).toBeNull();

    openFirstDetails();

    const element = fixture.nativeElement as HTMLElement;
    const text = normalizeSpaces(dialog()?.textContent ?? '');

    expect(dialog()).toBeTruthy();
    expect(text).toContain('VTE-000012');
    expect(text).toContain('Marteau 500 g');
    expect(text).toContain('Espèces');
    expect(text).toContain('11 000,00');
    expect(text).toContain('Facture PDF');
  });

  it('should download the invoice of a sale as a PDF', async () => {
    await flushSales([buildSale()]);

    // jsdom n'implemente pas le telechargement de blob : les URL temporaires
    // et le clic sur le lien sont donc remplaces par des espions.
    const createObjectURL = vi.fn(() => 'blob:facture');
    const revokeObjectURL = vi.fn();

    Object.defineProperty(URL, 'createObjectURL', {
      value: createObjectURL,
      configurable: true,
    });
    Object.defineProperty(URL, 'revokeObjectURL', {
      value: revokeObjectURL,
      configurable: true,
    });

    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

    const button = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button'),
    ).find((candidate) => candidate.textContent?.trim() === 'Facture PDF');

    button?.click();
    fixture.detectChanges();

    const request = httpMock.expectOne((candidate) =>
      candidate.url.endsWith('/api/sales/12/invoice'),
    );

    expect(request.request.method).toBe('GET');
    expect(request.request.responseType).toBe('blob');

    const pdf = new Blob(['%PDF-1.4'], { type: 'application/pdf' });
    request.flush(pdf);
    await settle();

    expect(createObjectURL).toHaveBeenCalledWith(pdf);
    expect(click).toHaveBeenCalled();
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:facture');

    click.mockRestore();
  });

  it('should close the details modal', async () => {
    await flushSales([buildSale()]);

    openFirstDetails();
    expect(dialog()).toBeTruthy();

    const close = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button'),
    ).find((candidate) => candidate.getAttribute('aria-label') === 'Fermer la modale');

    close?.click();
    fixture.detectChanges();

    expect(dialog()).toBeNull();
  });

  it('should filter the history by payment status', async () => {
    await flushSales([buildSale()]);

    filterBy('credit');

    const request = httpMock.expectOne(
      (candidate) =>
        candidate.url.endsWith('/api/sales') && candidate.params.get('payment_status') === 'credit',
    );

    request.flush(buildPage([]));
    await settle();
  });

  it('should reset the search and the status filter from the empty state', async () => {
    await flushSales([]);

    filterBy('credit');

    httpMock
      .expectOne(
        (candidate) =>
          candidate.url.endsWith('/api/sales') &&
          candidate.params.get('payment_status') === 'credit',
      )
      .flush(buildPage([]));
    await settle();

    const reset = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button'),
    ).find((candidate) => candidate.textContent?.trim() === 'Réinitialiser les filtres');

    expect(reset).toBeTruthy();
    reset?.click();
    fixture.detectChanges();

    const request = httpMock.expectOne(
      (candidate) =>
        candidate.url.endsWith('/api/sales') && !candidate.params.has('payment_status'),
    );

    request.flush(buildPage([]));
    await settle();
  });

  it('should send the search term to the API', async () => {
    await flushSales([buildSale()]);

    search('VTE-000012');

    // La recherche est stabilisee : une seule requete part apres une pause
    // dans la frappe.
    await new Promise((resolve) => setTimeout(resolve, 500));

    const request = httpMock.expectOne(
      (candidate) =>
        candidate.url.endsWith('/api/sales') && candidate.params.get('search') === 'VTE-000012',
    );

    request.flush(buildPage([]));
    await settle();
  });

  it('should paginate the history', async () => {
    await flushSales([buildSale()], { current_page: 1, last_page: 3, total: 45 });

    const next = Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('button')).find(
      (candidate) => candidate.textContent?.trim() === 'Suivant',
    );

    next?.click();
    fixture.detectChanges();

    const request = httpMock.expectOne(
      (candidate) => candidate.url.endsWith('/api/sales') && candidate.params.get('page') === '2',
    );

    request.flush(buildPage([buildSale()], { current_page: 2, last_page: 3, total: 45 }));
    await settle();

    expect(normalizeSpaces((fixture.nativeElement as HTMLElement).textContent ?? '')).toContain(
      'Page 2 sur 3',
    );
  });

  it('should list the manual payments waiting for verification', async () => {
    await flushSales(
      [
        buildSale({
          paid_amount: '0.00',
          pending_amount: '500.00',
          available_due: '0.00',
          balance_due: '500.00',
          payment_status: 'credit',
        }),
      ],
      {},
      [buildManualPayment()],
    );

    const text = normalizeSpaces((fixture.nativeElement as HTMLElement).textContent ?? '');

    expect(manualPaymentRows()).toHaveLength(1);
    expect(text).toContain('Vérification des paiements manuels');
    expect(text).toContain('VTE-000012');
    expect(text).toContain('Wave');
    expect(text).toContain('WAVE-42');
    expect(text).toContain('500,00');
    expect(manualPaymentRows()[0].textContent).toContain('En attente');
    expect(rows()[0].textContent).toContain('Paiement en attente');
  });

  it('should limit the manual search and distinguish filtered and global pending counts', async () => {
    await flushSales([], {}, [buildManualPayment()], { total: 8 }, 12);

    const element = fixture.nativeElement as HTMLElement;
    const input = element.querySelector('#manual-payment-search') as HTMLInputElement;
    const text = normalizeSpaces(element.textContent ?? '');

    expect(input.maxLength).toBe(255);
    expect(text).toContain('Total en attente : 12');
    expect(text).toContain('8 résultats');
  });

  it('should not invent totals when the API omits pagination metadata', async () => {
    httpMock.expectOne(isSalesRequest).flush(buildPage([]));
    await settle();

    httpMock
      .match(isManualListRequest)
      .forEach((request) => request.flush({ data: [buildManualPayment()] }));
    httpMock.match(isManualPendingCountRequest).forEach((request) => request.flush({ data: [] }));
    await settle();

    const element = fixture.nativeElement as HTMLElement;
    const text = normalizeSpaces(element.textContent ?? '');

    expect(
      element.querySelector('[title="Total global des paiements manuels en attente"]'),
    ).toBeNull();
    expect(text).toContain('1 affiché');
  });

  it('should paginate the manual queue and reset to the first page when filters change', async () => {
    await flushSales(
      [],
      {},
      [buildManualPayment()],
      { current_page: 1, last_page: 2, per_page: 10, total: 11, from: 1, to: 10 },
      11,
    );

    let text = normalizeSpaces((fixture.nativeElement as HTMLElement).textContent ?? '');

    expect(text).toContain('11 résultats');
    expect(text).toContain('Page 1 sur 2');

    clickManualPaginationButton('Suivant');

    const secondPage = httpMock.expectOne(
      (request) =>
        isManualListRequest(request) &&
        request.params.get('page') === '2' &&
        request.params.get('per_page') === '10',
    );

    secondPage.flush(
      buildManualPage([buildManualPayment({ id: 45 })], {
        current_page: 2,
        last_page: 2,
        per_page: 10,
        total: 11,
        from: 11,
        to: 11,
      }),
    );
    await settle();

    text = normalizeSpaces((fixture.nativeElement as HTMLElement).textContent ?? '');

    expect(text).toContain('Page 2 sur 2');
    expect(manualPaymentRows()).toHaveLength(1);

    selectValue('#manual-payment-status', 'confirmed');

    const filteredPage = httpMock.expectOne(
      (request) =>
        isManualListRequest(request) &&
        request.params.get('status') === 'confirmed' &&
        request.params.get('page') === '1',
    );

    filteredPage.flush(
      buildManualPage([], {
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
        from: null,
        to: null,
      }),
    );
    await settle();
  });

  it('should filter the manual payments by status, method and reference', async () => {
    await flushSales([buildSale()], {}, [buildManualPayment()]);

    selectValue('#manual-payment-status', 'confirmed');

    const statusRequest = httpMock.expectOne(
      (candidate) =>
        candidate.url.endsWith('/api/manual-payments') &&
        candidate.params.get('status') === 'confirmed',
    );

    statusRequest.flush({ data: [] });
    await settle();

    selectValue('#manual-payment-method', 'orange_money');

    const methodRequest = httpMock.expectOne(
      (candidate) =>
        candidate.url.endsWith('/api/manual-payments') &&
        candidate.params.get('status') === 'confirmed' &&
        candidate.params.get('method') === 'orange_money',
    );

    methodRequest.flush({ data: [] });
    await settle();

    searchManualPayments('OM-42');
    await new Promise((resolve) => setTimeout(resolve, 500));

    const searchRequest = httpMock.expectOne(
      (candidate) =>
        candidate.url.endsWith('/api/manual-payments') &&
        candidate.params.get('status') === 'confirmed' &&
        candidate.params.get('method') === 'orange_money' &&
        candidate.params.get('search') === 'OM-42',
    );

    searchRequest.flush({ data: [] });
    await settle();
  });

  it('should display confirmed and rejected manual payments with their decision', async () => {
    await flushSales([buildSale()], {}, [
      buildManualPayment({
        id: 45,
        status: 'confirmed',
        confirmed_at: '2026-10-22T10:00:00+00:00',
        confirmed_by_id: 1,
        confirmed_by_name: 'SuperAdmin',
      }),
      buildManualPayment({
        id: 46,
        status: 'rejected',
        reference: 'OM-77',
        method: 'orange_money',
        rejected_at: '2026-10-22T11:00:00+00:00',
        rejected_by_id: 1,
        rejected_by_name: 'SuperAdmin',
        rejection_reason: 'Référence introuvable',
      }),
    ]);

    const text = normalizeSpaces((fixture.nativeElement as HTMLElement).textContent ?? '');

    expect(manualPaymentRows()).toHaveLength(2);
    expect(text).toContain('Confirmé');
    expect(text).toContain('SuperAdmin');
    expect(text).toContain('Rejeté');
    expect(text).toContain('Référence introuvable');
    expect(text).toContain('OM-77');
  });

  it('should open the details of a manual payment', async () => {
    await flushSales([buildSale()], {}, [
      buildManualPayment({
        status: 'rejected',
        rejected_at: '2026-10-22T11:00:00+00:00',
        rejected_by_id: 1,
        rejected_by_name: 'SuperAdmin',
        rejection_reason: 'Référence introuvable',
      }),
    ]);

    clickButton('Détails');

    const detail = (fixture.nativeElement as HTMLElement).querySelector(
      '#manual-payment-detail-title',
    );

    expect(detail).toBeTruthy();
    expect(normalizeSpaces(dialog()?.textContent ?? '')).toContain('WAVE-42');
    expect(normalizeSpaces(dialog()?.textContent ?? '')).toContain('Référence introuvable');
    expect(normalizeSpaces(dialog()?.textContent ?? '')).toContain('Client de passage');

    clickButton('Fermer');

    expect(dialog()).toBeNull();
  });

  it('should confirm a manual payment and reload the queue', async () => {
    const pendingSale = buildSale({
      paid_amount: '0.00',
      pending_amount: '500.00',
      available_due: '0.00',
      balance_due: '500.00',
      payment_status: 'credit',
    });

    await flushSales([pendingSale], {}, [buildManualPayment()]);

    clickButton('Confirmer');

    const dialogText = normalizeSpaces(dialog()?.textContent ?? '');
    expect(dialogText).toContain('Confirmer le paiement');
    expect(dialogText).toContain('WAVE-42');

    clickButtonStartingWith('Confirmer le paiement');

    const request = httpMock.expectOne((candidate) =>
      candidate.url.endsWith('/api/manual-payments/44/confirm'),
    );

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({});

    request.flush({
      data: buildSale({
        paid_amount: '500.00',
        pending_amount: '0.00',
        available_due: '0.00',
        balance_due: '0.00',
        payment_status: 'paid',
      }),
    });
    await settle();

    expect(dialog()).toBeNull();
    expect(toastService.toasts()[0]?.message).toContain('confirmé');

    await flushManualPayments([]);
    httpMock.expectOne(isSalesRequest).flush(buildPage([]));
    await settle();

    expect(manualPaymentRows()).toHaveLength(0);
  });

  it('should not confirm a manual payment twice while the request is pending', async () => {
    const pendingSale = buildSale({
      paid_amount: '0.00',
      pending_amount: '500.00',
      available_due: '0.00',
      balance_due: '500.00',
      payment_status: 'credit',
    });

    await flushSales([pendingSale], {}, [buildManualPayment()]);

    clickButton('Confirmer');
    clickButtonStartingWith('Confirmer le paiement');

    const request = httpMock.expectOne((candidate) =>
      candidate.url.endsWith('/api/manual-payments/44/confirm'),
    );

    clickButtonStartingWith('Confirmer le paiement');

    httpMock.expectNone((candidate) => candidate.url.endsWith('/api/manual-payments/44/confirm'));

    request.flush({
      data: buildSale({
        paid_amount: '500.00',
        pending_amount: '0.00',
        available_due: '0.00',
        balance_due: '0.00',
        payment_status: 'paid',
      }),
    });
    await settle();

    await flushManualPayments([]);
    httpMock.expectOne(isSalesRequest).flush(buildPage([]));
    await settle();
  });

  it('should show the rejection error and keep the modal open on failure', async () => {
    await flushSales([buildSale()], {}, [buildManualPayment()]);

    clickButton('Rejeter');
    await settle();
    setField('#rejection-reason', 'Référence introuvable');
    clickButtonStartingWith('Rejeter le paiement');

    const request = httpMock.expectOne((candidate) =>
      candidate.url.endsWith('/api/manual-payments/44/reject'),
    );

    request.flush(
      { message: 'Ce paiement a déjà été traité.' },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    await settle();

    expect(normalizeSpaces(dialog()?.textContent ?? '')).toContain(
      'Ce paiement a déjà été traité.',
    );
    expect(toastService.toasts()).toHaveLength(0);
  });

  it('should require a reason and reject a manual payment', async () => {
    const pendingSale = buildSale({
      paid_amount: '0.00',
      pending_amount: '500.00',
      available_due: '0.00',
      balance_due: '500.00',
      payment_status: 'credit',
    });

    await flushSales([pendingSale], {}, [buildManualPayment()]);

    clickButton('Rejeter');
    await settle();
    clickButtonStartingWith('Rejeter le paiement');

    expect(normalizeSpaces(dialog()?.textContent ?? '')).toContain(
      'Le motif du rejet est obligatoire.',
    );
    httpMock.expectNone((candidate) => candidate.url.endsWith('/api/manual-payments/44/reject'));

    setField('#rejection-reason', 'Référence introuvable');
    clickButtonStartingWith('Rejeter le paiement');

    const request = httpMock.expectOne((candidate) =>
      candidate.url.endsWith('/api/manual-payments/44/reject'),
    );

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ reason: 'Référence introuvable' });

    request.flush({ data: pendingSale });
    await settle();

    expect(dialog()).toBeNull();
    expect(toastService.toasts()[0]?.message).toContain('rejeté');

    await flushManualPayments([]);
    httpMock.expectOne(isSalesRequest).flush(buildPage([]));
    await settle();
  });

  it('should require a reference for Wave and Orange Money payments', async () => {
    const creditSale = buildSale({
      paid_amount: '0.00',
      pending_amount: '0.00',
      available_due: '1000.00',
      balance_due: '1000.00',
      total_amount: '1000.00',
      payment_status: 'credit',
    });

    await flushSales([creditSale]);

    for (const method of ['wave', 'orange_money']) {
      clickButton('Encaisser');
      await settle();
      selectValue('#payment-method', method);
      clickButton('Enregistrer');

      expect(normalizeSpaces(dialog()?.textContent ?? '')).toContain(
        'La référence de transaction est obligatoire pour Wave et Orange Money.',
      );
      httpMock.expectNone(isAddPaymentRequest);

      clickButton('Annuler');
    }
  });

  it('should send the manual payment reference and announce its verification', async () => {
    const creditSale = buildSale({
      paid_amount: '0.00',
      pending_amount: '0.00',
      available_due: '1000.00',
      balance_due: '1000.00',
      total_amount: '1000.00',
      payment_status: 'credit',
    });

    await flushSales([creditSale]);

    clickButton('Encaisser');
    await settle();
    selectValue('#payment-method', 'wave');
    setField('#payment-reference', 'WAVE-999');
    clickButton('Enregistrer');

    const request = httpMock.expectOne(isAddPaymentRequest);

    expect(request.request.body).toEqual({
      amount: 1000,
      method: 'wave',
      reference: 'WAVE-999',
      idempotency_key: expect.any(String),
    });

    request.flush({
      data: buildSale({
        paid_amount: '0.00',
        pending_amount: '1000.00',
        available_due: '0.00',
        balance_due: '1000.00',
        total_amount: '1000.00',
        payment_status: 'credit',
      }),
    });
    await settle();

    expect(dialog()).toBeNull();
    expect(toastService.toasts()[0]?.message).toContain('en attente de vérification');

    await flushManualPayments([]);
    httpMock.expectOne(isSalesRequest).flush(buildPage([]));
    await settle();
  });

  it('should reuse the payment idempotency key after a failed retry', async () => {
    const creditSale = buildSale({
      paid_amount: '0.00',
      pending_amount: '0.00',
      available_due: '1000.00',
      balance_due: '1000.00',
      total_amount: '1000.00',
      payment_status: 'credit',
    });

    await flushSales([creditSale]);

    clickButton('Encaisser');
    await settle();
    selectValue('#payment-method', 'wave');
    setField('#payment-reference', 'WAVE-999');
    clickButton('Enregistrer');

    const firstRequest = httpMock.expectOne(isAddPaymentRequest);
    const firstKey = firstRequest.request.body.idempotency_key;

    firstRequest.flush(
      { message: 'Erreur temporaire.' },
      { status: 500, statusText: 'Server Error' },
    );
    await settle();

    clickButton('Enregistrer');

    const secondRequest = httpMock.expectOne(isAddPaymentRequest);

    expect(secondRequest.request.body.idempotency_key).toBe(firstKey);
    secondRequest.flush({
      data: buildSale({
        paid_amount: '0.00',
        pending_amount: '1000.00',
        available_due: '0.00',
        balance_due: '1000.00',
        total_amount: '1000.00',
        payment_status: 'credit',
      }),
    });
    await settle();

    expect(dialog()).toBeNull();
  });
});
