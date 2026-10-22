import { registerLocaleData } from '@angular/common';
import localeFr from '@angular/common/locales/fr';
import { HttpRequest, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { LOCALE_ID } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { ToastService } from '../../core/toast/toast-service';
import { CashSession } from '../../services/cash.model';
import { Customer, CustomersPage } from '../../services/customer.model';
import { Product, ProductsPage } from '../../services/product.model';
import { Sale } from '../../services/sale.model';
import { Pos } from './pos';

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

function buildProduct(overrides: Partial<Product> = {}): Product {
  return {
    id: 1,
    sku: 'BOUL-M8',
    name: 'Boulon M8',
    category: { id: 1, name: 'Fixations' },
    unit: { id: 1, name: 'Pièce', symbol: 'pce' },
    purchase_price: '150.00',
    sale_price: '250.00',
    stock_quantity: 100,
    min_stock_alert: 10,
    is_active: true,
    is_low_stock: false,
    ...overrides,
  };
}

function buildProductsPage(data: Product[]): ProductsPage {
  return {
    data,
    meta: {
      current_page: 1,
      from: data.length ? 1 : null,
      last_page: 1,
      per_page: 100,
      to: data.length ? data.length : null,
      total: data.length,
    },
  };
}

function buildCustomer(overrides: Partial<Customer> = {}): Customer {
  return {
    id: 3,
    name: 'Client Passage',
    phone: '+221 77 512 34 56',
    email: 'contact@clientpassage.sn',
    address: 'Dakar Plateau',
    credit_limit: '500000.00',
    outstanding_balance: '0.00',
    is_active: true,
    ...overrides,
  };
}

function buildCustomersPage(data: Customer[]): CustomersPage {
  return {
    data,
    meta: {
      current_page: 1,
      from: data.length ? 1 : null,
      last_page: 1,
      per_page: 100,
      to: data.length ? data.length : null,
      total: data.length,
    },
  };
}

function buildCashSession(overrides: Partial<CashSession> = {}): CashSession {
  return {
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
    ...overrides,
  };
}

function buildSale(overrides: Partial<Sale> = {}): Sale {
  return {
    id: 12,
    reference: 'VTE-000012',
    customer_id: null,
    customer_name: null,
    user_id: 1,
    user_name: 'Vendeur',
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

describe('Pos', () => {
  let fixture: ComponentFixture<Pos>;
  let httpMock: HttpTestingController;
  let toastService: ToastService;

  /** Requete de la session de caisse courante. */
  const isCurrentSessionRequest = (request: HttpRequest<unknown>) =>
    request.url.endsWith('/api/cash-sessions/current');

  /** Requete d'ouverture de la caisse. */
  const isOpenSessionRequest = (request: HttpRequest<unknown>) =>
    request.url.endsWith('/api/cash-sessions/open');

  /** Requete de cloture de la caisse. */
  const isCloseSessionRequest = (request: HttpRequest<unknown>) =>
    request.url.endsWith('/api/cash-sessions/close');

  /** Requete du catalogue produits. */
  const isProductsRequest = (request: HttpRequest<unknown>) =>
    request.url.endsWith('/api/products');

  /** Requete du fichier clients. */
  const isCustomersRequest = (request: HttpRequest<unknown>) =>
    request.url.endsWith('/api/customers');

  /** Requete d'enregistrement d'une vente. */
  const isSalesRequest = (request: HttpRequest<unknown>) => request.url.endsWith('/api/sales');

  /**
   * Laisse les reponses HTTP et les signaux se propager.
   *
   * `fixture.whenStable()` ne peut pas etre utilise ici : les ressources
   * `httpResource` (catalogue, clients) laissent des requetes en attente, qui
   * bloqueraient l'attente.
   */
  async function settle(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0));
    fixture.detectChanges();
  }

  /** Repond a la verification de la session et stabilise la vue. */
  async function flushSession(session: CashSession | null): Promise<void> {
    httpMock.expectOne(isCurrentSessionRequest).flush({ data: session });
    await settle();
  }

  /** Repond au catalogue et au fichier clients, puis stabilise la vue. */
  async function flushCatalogue(products: Product[] = []): Promise<void> {
    await settle();
    httpMock.expectOne(isProductsRequest).flush(buildProductsPage(products));
    httpMock.expectOne(isCustomersRequest).flush(buildCustomersPage([buildCustomer()]));
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

  /** Modale de la caisse, si elle est affichee. */
  function dialog(): HTMLElement | null {
    return (fixture.nativeElement as HTMLElement).querySelector('[role="dialog"]');
  }

  /** Bouton dont le libelle commence par le texte donne. */
  function buttonStartingWith(text: string): HTMLButtonElement | undefined {
    return Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('button')).find(
      (candidate) => candidate.textContent?.trim().startsWith(text),
    ) as HTMLButtonElement | undefined;
  }

  /** Bouton designe par son libelle accessible. */
  function buttonByLabel(label: string): HTMLButtonElement | undefined {
    return (fixture.nativeElement as HTMLElement).querySelector(`button[aria-label="${label}"]`) as
      HTMLButtonElement | undefined;
  }

  /** Saisit une valeur dans un champ du formulaire. */
  function setField(selector: string, value: string): void {
    const input = (fixture.nativeElement as HTMLElement).querySelector(
      selector,
    ) as HTMLInputElement;

    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  /** Soumet le formulaire d'ouverture de la caisse. */
  function submitOpeningForm(): void {
    const form = (fixture.nativeElement as HTMLElement).querySelector('form') as HTMLFormElement;

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Pos],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: LOCALE_ID, useValue: 'fr-FR' },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(Pos);
    httpMock = TestBed.inject(HttpTestingController);
    toastService = TestBed.inject(ToastService);

    // Rendu initial : la session de caisse est verifiee par le composant.
    fixture.detectChanges();
  });

  afterEach(() => {
    httpMock.match(isCurrentSessionRequest).forEach((request) => request.flush({ data: null }));
    httpMock.match(isProductsRequest).forEach((request) => request.flush(buildProductsPage([])));
    httpMock.match(isCustomersRequest).forEach((request) => request.flush(buildCustomersPage([])));
    httpMock
      .match(isOpenSessionRequest)
      .forEach((request) => request.flush({ data: buildCashSession() }));
    httpMock
      .match(isCloseSessionRequest)
      .forEach((request) => request.flush({ data: buildCashSession({ status: 'closed' }) }));
    httpMock.match(isSalesRequest).forEach((request) => request.flush({ data: buildSale() }));
  });

  it('should create', () => {
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('should check the cash session when the screen opens', () => {
    httpMock.expectOne(isCurrentSessionRequest);
  });

  it('should display a blocking modal when no cash session is open', async () => {
    await flushSession(null);

    const element = fixture.nativeElement as HTMLElement;

    expect(dialog()).toBeTruthy();
    expect(element.textContent).toContain('Ouvrir la caisse');
    expect(element.textContent).toContain('Fond de caisse initial');
  });

  it('should open the cash session and display the catalogue', async () => {
    await flushSession(null);

    setField('#pos-opening-balance', '50000');
    submitOpeningForm();

    const request = httpMock.expectOne(isOpenSessionRequest);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ opening_balance: 50000 });

    request.flush({ data: buildCashSession() });
    await flushCatalogue([buildProduct()]);

    const element = fixture.nativeElement as HTMLElement;

    expect(dialog()).toBeNull();
    expect(element.textContent).toContain('Boulon M8');
    expect(element.textContent).toContain('Caisse ouverte');
  });

  it('should find the products by name or by SKU', async () => {
    await flushSession(buildCashSession());
    await flushCatalogue([
      buildProduct({ id: 1, name: 'Boulon M8', sku: 'BOUL-M8' }),
      buildProduct({ id: 2, name: 'Écrou M8', sku: 'ECRO-M8' }),
    ]);

    const element = fixture.nativeElement as HTMLElement;

    search('boulon');
    expect(element.textContent).toContain('Boulon M8');
    expect(element.textContent).not.toContain('Écrou M8');

    search('ECRO');
    expect(element.textContent).toContain('Écrou M8');
    expect(element.textContent).not.toContain('Boulon M8');
  });

  it('should add a product to the cart and update the total', async () => {
    await flushSession(buildCashSession());
    await flushCatalogue([buildProduct()]);

    const element = fixture.nativeElement as HTMLElement;

    buttonStartingWith('Boulon M8')?.click();
    fixture.detectChanges();

    expect(normalizeSpaces(element.textContent ?? '')).toContain('250,00');

    // Le total general suit la quantite.
    const total = element.querySelector('[aria-live="polite"]')?.textContent ?? '';
    expect(normalizeSpaces(total)).toContain('250,00');
  });

  it('should not add more units than the available stock', async () => {
    await flushSession(buildCashSession());
    await flushCatalogue([buildProduct({ stock_quantity: 1 })]);

    const element = fixture.nativeElement as HTMLElement;

    buttonStartingWith('Boulon M8')?.click();
    fixture.detectChanges();

    buttonByLabel('Ajouter une unité de Boulon M8')?.click();
    fixture.detectChanges();

    expect(toastService.toasts()[0]?.message).toContain('Stock insuffisant');
    expect(normalizeSpaces(element.textContent ?? '')).toContain('Total général 250,00');
  });

  it('should record the sale and empty the cart', async () => {
    await flushSession(buildCashSession());
    await flushCatalogue([buildProduct()]);

    const element = fixture.nativeElement as HTMLElement;

    buttonStartingWith('Boulon M8')?.click();
    fixture.detectChanges();

    buttonStartingWith('Valider la vente')?.click();
    fixture.detectChanges();

    const request = httpMock.expectOne(isSalesRequest);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      customer_id: null,
      items: [{ product_id: 1, quantity: 1 }],
      payment: { method: 'cash', amount: 250 },
    });

    request.flush({ data: buildSale() });
    await settle();

    const total = element.querySelector('[aria-live="polite"]')?.textContent ?? '';
    expect(normalizeSpaces(total)).toContain('0,00');
    expect(toastService.toasts()[0]?.message).toContain('VTE-000012');
  });

  it('should refuse a credit sale without a customer', async () => {
    await flushSession(buildCashSession());
    await flushCatalogue([buildProduct()]);

    const element = fixture.nativeElement as HTMLElement;

    buttonStartingWith('Boulon M8')?.click();
    fixture.detectChanges();

    buttonStartingWith('Crédit')?.click();
    fixture.detectChanges();

    buttonStartingWith('Valider la vente')?.click();
    fixture.detectChanges();

    expect(element.textContent).toContain('Sélectionnez un client');
    httpMock.expectNone(isSalesRequest);
  });

  it('should send the customer and omit the payment for a credit sale', async () => {
    await flushSession(buildCashSession());
    await flushCatalogue([buildProduct()]);

    const element = fixture.nativeElement as HTMLElement;

    buttonStartingWith('Boulon M8')?.click();
    fixture.detectChanges();

    buttonStartingWith('Crédit')?.click();
    fixture.detectChanges();

    const select = element.querySelector('#pos-customer') as HTMLSelectElement;
    select.value = '3';
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    buttonStartingWith('Valider la vente')?.click();
    fixture.detectChanges();

    const request = httpMock.expectOne(isSalesRequest);
    expect(request.request.body).toEqual({
      customer_id: 3,
      items: [{ product_id: 1, quantity: 1 }],
    });
  });

  it('should close the cash session and show the difference', async () => {
    await flushSession(buildCashSession());
    await flushCatalogue([]);

    const element = fixture.nativeElement as HTMLElement;

    buttonStartingWith('Clôturer la caisse')?.click();
    fixture.detectChanges();

    expect(dialog()).toBeTruthy();

    // La modale rappelle le total des ventes en especes de la session.
    expect(element.textContent).toContain('Ventes en espèces de la session');
    expect(element.textContent).toContain('Montant compté en espèces');

    setField('#pos-closing-balance', '48000');

    const form = element.querySelector('form') as HTMLFormElement;
    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    const request = httpMock.expectOne(isCloseSessionRequest);
    expect(request.request.body).toEqual({ closing_balance: 48000 });

    request.flush({
      data: buildCashSession({
        status: 'closed',
        closing_balance: '48000.00',
        cash_received: '5000.00',
        expenses_total: '0.00',
        expected_cash: '55000.00',
        difference: '-7000.00',
        opening_difference: '-2000.00',
      }),
    });
    await settle();

    expect(element.textContent).toContain('Caisse clôturée');
    expect(normalizeSpaces(element.textContent ?? '')).toContain('-7 000,00');

    // Un ecart negatif est signale en rouge.
    const toast = toastService.toasts()[0];
    expect(normalizeSpaces(toast?.message ?? '')).toBe(
      'Caisse clôturée avec succès. Écart : -7 000,00 FCFA',
    );
    expect(toast?.type).toBe('error');
  });

  it('should report a positive difference after closing the cash session', async () => {
    await flushSession(buildCashSession());
    await flushCatalogue([]);

    buttonStartingWith('Clôturer la caisse')?.click();
    fixture.detectChanges();

    setField('#pos-closing-balance', '56000');

    const form = (fixture.nativeElement as HTMLElement).querySelector('form') as HTMLFormElement;
    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    const request = httpMock.expectOne(isCloseSessionRequest);
    request.flush({
      data: buildCashSession({
        status: 'closed',
        closing_balance: '56000.00',
        cash_received: '5000.00',
        expenses_total: '0.00',
        expected_cash: '55000.00',
        difference: '1000.00',
        opening_difference: '6000.00',
      }),
    });
    await settle();

    const toast = toastService.toasts()[0];
    expect(normalizeSpaces(toast?.message ?? '')).toBe(
      'Caisse clôturée avec succès. Écart : 1 000,00 FCFA',
    );
    expect(toast?.type).toBe('success');
  });
});
