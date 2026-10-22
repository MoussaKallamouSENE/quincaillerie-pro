import { registerLocaleData } from '@angular/common';
import localeFr from '@angular/common/locales/fr';
import { provideHttpClient, HttpRequest } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { LOCALE_ID } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { ToastService } from '../../core/toast/toast-service';
import type { Customer, CustomersPage } from '../../services/customer.model';
import { CustomerList } from './customer-list';

// L'application s'execute en francais (`app.config.ts`) : les specs doivent
// reproduire cette locale pour que les montants soient formates pareil.
registerLocaleData(localeFr);

function buildCustomer(overrides: Partial<Customer> = {}): Customer {
  return {
    id: 1,
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

/**
 * Remplace les espaces typographiques par une espace normale.
 *
 * En locale francaise, le separateur de milliers est une espace fine
 * insecable (U+202F) : sans cette normalisation, les montants formates ne
 * sont pas comparables a une chaine ecrite a la main.
 */
function normalizeSpaces(text: string): string {
  return text.replace(/[  ]/g, ' ');
}

function buildPage(data: Customer[]): CustomersPage {
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

describe('CustomerList', () => {
  let fixture: ComponentFixture<CustomerList>;
  let httpMock: HttpTestingController;
  let toastService: ToastService;

  /** Requete du fichier clients. */
  const isCustomersRequest = (request: HttpRequest<unknown>) =>
    request.url.endsWith('/api/customers');

  /** Repond au fichier clients et stabilise la vue. */
  async function flushCustomers(customers: Customer[]): Promise<void> {
    httpMock.expectOne(isCustomersRequest).flush(buildPage(customers));

    await fixture.whenStable();
    fixture.detectChanges();
  }

  /**
   * Laisse les reponses HTTP et les signaux se propager.
   *
   * `fixture.whenStable()` ne peut pas etre utilise ici : le rechargement du
   * fichier clients laisse une requete en attente, qui bloquerait l'attente.
   */
  async function settle(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0));
    fixture.detectChanges();
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

  /** Lignes du tableau des clients. */
  function rows(): HTMLTableRowElement[] {
    return Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('tbody tr'),
    ) as HTMLTableRowElement[];
  }

  /** Modale de creation, si elle est ouverte. */
  function dialog(): HTMLElement | null {
    return (fixture.nativeElement as HTMLElement).querySelector('[role="dialog"]');
  }

  /** Ouvre la modale de creation. */
  function openModal(): void {
    const button = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button'),
    ).find((candidate) => candidate.textContent?.trim().startsWith('Nouveau Client'));

    button?.click();
    fixture.detectChanges();
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

  /** Remplit le formulaire avec un client valide. */
  function fillForm(): void {
    setField('#customer-name', 'Boutique Chez Ali');
    setField('#customer-phone', '+221 78 987 65 43');
    setField('#customer-email', 'boutique.chezali@example.sn');
    setField('#customer-address', 'Kaolack');
    setField('#customer-credit-limit', '250000');
  }

  /** Soumet le formulaire de creation. */
  function submitForm(): void {
    const form = (fixture.nativeElement as HTMLElement).querySelector('form') as HTMLFormElement;

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [CustomerList],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: LOCALE_ID, useValue: 'fr-FR' },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(CustomerList);
    httpMock = TestBed.inject(HttpTestingController);
    toastService = TestBed.inject(ToastService);

    // Rendu initial et execution des effets : la requete du fichier clients
    // est emise par la ressource de la page.
    fixture.detectChanges();
  });

  afterEach(() => {
    for (const request of httpMock.match(isCustomersRequest)) {
      request.flush(request.request.method === 'POST' ? { data: buildCustomer() } : buildPage([]));
    }
  });

  it('should create', () => {
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('should announce the loading of the customers', () => {
    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Chargement des clients');
  });

  it('should render the customers returned by the API', async () => {
    await flushCustomers([
      buildCustomer(),
      buildCustomer({
        id: 2,
        name: 'Moussa Diop',
        phone: '+221 76 123 45 67',
        credit_limit: '150000.00',
      }),
    ]);

    const element = fixture.nativeElement as HTMLElement;
    const text = normalizeSpaces(element.textContent ?? '');

    expect(rows()).toHaveLength(2);
    expect(text).toContain('Client Passage');
    expect(text).toContain('+221 77 512 34 56');
    expect(text).toContain('Moussa Diop');
    expect(text).toContain('500 000,00');
    expect(text).toContain('150 000,00');
  });

  it('should display the debt in red when it is greater than zero', async () => {
    await flushCustomers([
      buildCustomer({ id: 1, name: 'Client endetté', outstanding_balance: '12500.00' }),
      buildCustomer({ id: 2, name: 'Client solvable', outstanding_balance: '0.00' }),
    ]);

    const [debtRow, cleanRow] = rows();
    const debt = normalizeSpaces(debtRow.querySelector('.text-red-400')?.textContent ?? '');

    expect(debt).toContain('12 500,00');
    expect(cleanRow.querySelector('.text-red-400')).toBeNull();
  });

  it('should filter the customers by name', async () => {
    await flushCustomers([
      buildCustomer({ id: 1, name: 'Moussa Diop' }),
      buildCustomer({ id: 2, name: 'Boutique Chez Ali' }),
    ]);

    search('moussa');

    expect(rows()).toHaveLength(1);
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Moussa Diop');
  });

  it('should ignore the accents when filtering', async () => {
    await flushCustomers([
      buildCustomer({ id: 1, name: 'Boutique Chez Ali' }),
      buildCustomer({ id: 2, name: 'Moussa Diop' }),
    ]);

    search('boutique');

    expect(rows()).toHaveLength(1);
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Boutique Chez Ali');
  });

  it('should show an empty state when the search matches nothing', async () => {
    await flushCustomers([buildCustomer()]);

    search('inexistant');

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Aucun client ne correspond');
  });

  it('should show an error state and allow retrying', async () => {
    httpMock
      .expectOne(isCustomersRequest)
      .flush(
        { message: 'Erreur interne du serveur.' },
        { status: 500, statusText: 'Server Error' },
      );

    await fixture.whenStable();
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Fichier clients indisponible');
    expect(element.textContent).toContain('Erreur interne du serveur.');

    const retry = Array.from(element.querySelectorAll('button')).find((button) =>
      button.textContent?.trim().startsWith('Réessayer'),
    );

    expect(retry).toBeTruthy();
    retry?.click();
    fixture.detectChanges();

    httpMock.expectOne(isCustomersRequest).flush(buildPage([buildCustomer()]));

    await fixture.whenStable();
    fixture.detectChanges();

    expect(element.textContent).toContain('Client Passage');
  });

  it('should display a button to create a customer', () => {
    const element = fixture.nativeElement as HTMLElement;
    const button = Array.from(element.querySelectorAll('button')).find((candidate) =>
      candidate.textContent?.trim().startsWith('Nouveau Client'),
    );

    expect(button).toBeTruthy();
  });

  it('should open the creation modal', async () => {
    await flushCustomers([buildCustomer()]);

    expect(dialog()).toBeNull();

    openModal();

    expect(dialog()).toBeTruthy();
  });

  it('should close the modal with the cancel button', async () => {
    await flushCustomers([buildCustomer()]);
    openModal();

    const cancel = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button'),
    ).find((candidate) => candidate.textContent?.trim() === 'Annuler');

    cancel?.click();
    fixture.detectChanges();

    expect(dialog()).toBeNull();
  });

  it('should focus the name field when the modal opens', async () => {
    await flushCustomers([buildCustomer()]);
    openModal();

    const name = (fixture.nativeElement as HTMLElement).querySelector(
      '#customer-name',
    ) as HTMLInputElement;

    expect(document.activeElement).toBe(name);
  });

  it('should not send anything when the form is incomplete', async () => {
    await flushCustomers([buildCustomer()]);
    openModal();

    setField('#customer-phone', '+221 77 000 00 00');
    submitForm();

    expect(httpMock.match(isCustomersRequest)).toHaveLength(0);

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Ce champ est obligatoire.');
    expect(dialog()).toBeTruthy();
  });

  it('should create the customer, close the modal and refresh the file', async () => {
    await flushCustomers([buildCustomer()]);
    openModal();

    fillForm();
    submitForm();

    const request = httpMock.expectOne(isCustomersRequest);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      name: 'Boutique Chez Ali',
      phone: '+221 78 987 65 43',
      email: 'boutique.chezali@example.sn',
      address: 'Kaolack',
      credit_limit: 250000,
    });

    request.flush({ data: buildCustomer({ id: 9, name: 'Boutique Chez Ali' }) });

    await settle();

    // La modale est fermee et un toast de succes est publie.
    expect(dialog()).toBeNull();
    expect(toastService.toasts()).toHaveLength(1);
    expect(toastService.toasts()[0].type).toBe('success');
    expect(toastService.toasts()[0].message).toContain('Boutique Chez Ali');

    // Le fichier clients est recharge.
    httpMock
      .expectOne(isCustomersRequest)
      .flush(buildPage([buildCustomer(), buildCustomer({ id: 9, name: 'Boutique Chez Ali' })]));

    await settle();

    expect(rows()).toHaveLength(2);
  });

  it('should display the server error when the creation fails', async () => {
    await flushCustomers([buildCustomer()]);
    openModal();

    fillForm();
    submitForm();

    httpMock
      .expectOne(isCustomersRequest)
      .flush(
        { message: 'Erreur interne du serveur.' },
        { status: 500, statusText: 'Server Error' },
      );

    await settle();

    const element = fixture.nativeElement as HTMLElement;

    // La modale reste ouverte pour permettre de corriger la saisie.
    expect(dialog()).toBeTruthy();
    expect(element.textContent).toContain('Erreur interne du serveur.');
    expect(toastService.toasts()).toHaveLength(0);
  });
});
