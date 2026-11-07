import { registerLocaleData } from '@angular/common';
import localeFr from '@angular/common/locales/fr';
import { provideHttpClient, HttpRequest } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { LOCALE_ID } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import type { Sale, SalesPage } from '../../services/sale.model';
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
    balance_due: '0.00',
    payment_status: 'paid',
    items: [],
    payments: [],
    created_at: '2026-10-22T09:00:00+00:00',
    updated_at: '2026-10-22T09:00:00+00:00',
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

describe('SaleListComponent', () => {
  let fixture: ComponentFixture<SaleListComponent>;
  let httpMock: HttpTestingController;

  /** Requete de l'historique des ventes. */
  const isSalesRequest = (request: HttpRequest<unknown>) => request.url.endsWith('/api/sales');

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
  ): Promise<void> {
    httpMock.expectOne(isSalesRequest).flush(buildPage(sales, overrides));
    await settle();
  }

  /** Saisit un terme dans la barre de recherche. */
  function search(term: string): void {
    const input = (fixture.nativeElement as HTMLElement).querySelector(
      'input[type="search"]',
    ) as HTMLInputElement;

    input.value = term;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
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
    return Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('tbody tr'),
    ) as HTMLTableRowElement[];
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

    // Rendu initial et execution des effets : la requete de l'historique est
    // emise par la ressource de la page.
    fixture.detectChanges();
  });

  afterEach(() => {
    for (const request of httpMock.match(isSalesRequest)) {
      request.flush(buildPage([]));
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
        payments: [
          {
            id: 1,
            amount: '11000.00',
            method: 'cash',
            reference: null,
            created_at: '2026-10-22T09:00:00+00:00',
          },
        ],
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
});
