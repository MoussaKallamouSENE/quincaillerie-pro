import { provideHttpClient, HttpRequest } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { ToastService } from '../../core/toast/toast-service';
import type { Supplier, SuppliersPage } from '../../services/supplier.model';
import { SupplierList } from './supplier-list';

function buildSupplier(overrides: Partial<Supplier> = {}): Supplier {
  return {
    id: 1,
    name: 'Cimenterie du Sahel',
    contact_person: 'Amadou Fall',
    phone: '+221 77 333 22 11',
    email: 'contact@cimenterie-sahel.sn',
    address: 'Thiès, zone industrielle',
    is_active: true,
    ...overrides,
  };
}

function buildPage(data: Supplier[]): SuppliersPage {
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

describe('SupplierList', () => {
  let fixture: ComponentFixture<SupplierList>;
  let httpMock: HttpTestingController;
  let toastService: ToastService;

  /** Requete du fichier fournisseurs. */
  const isSuppliersRequest = (request: HttpRequest<unknown>) =>
    request.url.endsWith('/api/suppliers');

  /** Repond au fichier fournisseurs et stabilise la vue. */
  async function flushSuppliers(suppliers: Supplier[]): Promise<void> {
    httpMock.expectOne(isSuppliersRequest).flush(buildPage(suppliers));

    await fixture.whenStable();
    fixture.detectChanges();
  }

  /**
   * Laisse les reponses HTTP et les signaux se propager.
   *
   * `fixture.whenStable()` ne peut pas etre utilise ici : le rechargement du
   * fichier fournisseurs laisse une requete en attente, qui bloquerait
   * l'attente.
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

  /** Lignes du tableau des fournisseurs. */
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
    ).find((candidate) => candidate.textContent?.trim().startsWith('Nouveau Fournisseur'));

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

  /** Remplit le formulaire avec un fournisseur valide. */
  function fillForm(): void {
    setField('#supplier-name', 'Quincaillerie Centrale');
    setField('#supplier-contact', 'Fatou Ndiaye');
    setField('#supplier-phone', '+221 70 111 22 33');
    setField('#supplier-email', 'commercial@quincaillerie-centrale.sn');
    setField('#supplier-address', 'Dakar, Rufisque');
  }

  /** Soumet le formulaire de creation. */
  function submitForm(): void {
    const form = (fixture.nativeElement as HTMLElement).querySelector('form') as HTMLFormElement;

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [SupplierList],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();

    fixture = TestBed.createComponent(SupplierList);
    httpMock = TestBed.inject(HttpTestingController);
    toastService = TestBed.inject(ToastService);

    // Rendu initial et execution des effets : la requete du fichier
    // fournisseurs est emise par la ressource de la page.
    fixture.detectChanges();
  });

  afterEach(() => {
    for (const request of httpMock.match(isSuppliersRequest)) {
      request.flush(request.request.method === 'POST' ? { data: buildSupplier() } : buildPage([]));
    }
  });

  it('should create', () => {
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('should announce the loading of the suppliers', () => {
    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Chargement des fournisseurs');
  });

  it('should render the suppliers returned by the API', async () => {
    await flushSuppliers([
      buildSupplier(),
      buildSupplier({
        id: 2,
        name: 'Quincaillerie Centrale',
        contact_person: 'Fatou Ndiaye',
        phone: '+221 70 111 22 33',
        email: 'commercial@quincaillerie-centrale.sn',
      }),
    ]);

    const element = fixture.nativeElement as HTMLElement;
    const text = element.textContent ?? '';

    expect(rows()).toHaveLength(2);
    expect(text).toContain('Cimenterie du Sahel');
    expect(text).toContain('Amadou Fall');
    expect(text).toContain('+221 77 333 22 11');
    expect(text).toContain('contact@cimenterie-sahel.sn');
    expect(text).toContain('Quincaillerie Centrale');
    expect(text).toContain('Fatou Ndiaye');
  });

  it('should replace the missing contact details with a dash', async () => {
    await flushSuppliers([
      buildSupplier({
        id: 1,
        name: 'Négoce General',
        contact_person: null,
        phone: null,
        email: null,
      }),
    ]);

    const element = fixture.nativeElement as HTMLElement;

    expect(element.textContent).toContain('Négoce General');
    expect(rows()[0].querySelectorAll('td')[1].textContent?.trim()).toBe('—');
    expect(rows()[0].querySelectorAll('td')[2].textContent?.trim()).toBe('—');
    expect(rows()[0].querySelectorAll('td')[3].textContent?.trim()).toBe('—');
  });

  it('should filter the suppliers by name', async () => {
    await flushSuppliers([
      buildSupplier({ id: 1, name: 'Cimenterie du Sahel' }),
      buildSupplier({ id: 2, name: 'Quincaillerie Centrale' }),
    ]);

    search('quincaillerie');

    expect(rows()).toHaveLength(1);
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Quincaillerie Centrale');
  });

  it('should show an empty state when the search matches nothing', async () => {
    await flushSuppliers([buildSupplier()]);

    search('inexistant');

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Aucun fournisseur ne correspond');
  });

  it('should show an error state and allow retrying', async () => {
    httpMock
      .expectOne(isSuppliersRequest)
      .flush(
        { message: 'Erreur interne du serveur.' },
        { status: 500, statusText: 'Server Error' },
      );

    await fixture.whenStable();
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Fichier fournisseurs indisponible');
    expect(element.textContent).toContain('Erreur interne du serveur.');

    const retry = Array.from(element.querySelectorAll('button')).find((button) =>
      button.textContent?.trim().startsWith('Réessayer'),
    );

    expect(retry).toBeTruthy();
    retry?.click();
    fixture.detectChanges();

    httpMock.expectOne(isSuppliersRequest).flush(buildPage([buildSupplier()]));

    await fixture.whenStable();
    fixture.detectChanges();

    expect(element.textContent).toContain('Cimenterie du Sahel');
  });

  it('should display a button to create a supplier', () => {
    const element = fixture.nativeElement as HTMLElement;
    const button = Array.from(element.querySelectorAll('button')).find((candidate) =>
      candidate.textContent?.trim().startsWith('Nouveau Fournisseur'),
    );

    expect(button).toBeTruthy();
  });

  it('should open the creation modal', async () => {
    await flushSuppliers([buildSupplier()]);

    expect(dialog()).toBeNull();

    openModal();

    expect(dialog()).toBeTruthy();
  });

  it('should close the modal with the cancel button', async () => {
    await flushSuppliers([buildSupplier()]);
    openModal();

    const cancel = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button'),
    ).find((candidate) => candidate.textContent?.trim() === 'Annuler');

    cancel?.click();
    fixture.detectChanges();

    expect(dialog()).toBeNull();
  });

  it('should focus the name field when the modal opens', async () => {
    await flushSuppliers([buildSupplier()]);
    openModal();

    const name = (fixture.nativeElement as HTMLElement).querySelector(
      '#supplier-name',
    ) as HTMLInputElement;

    expect(document.activeElement).toBe(name);
  });

  it('should not send anything when the form is incomplete', async () => {
    await flushSuppliers([buildSupplier()]);
    openModal();

    setField('#supplier-phone', '+221 77 000 00 00');
    submitForm();

    expect(httpMock.match(isSuppliersRequest)).toHaveLength(0);

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Ce champ est obligatoire.');
    expect(dialog()).toBeTruthy();
  });

  it('should create the supplier, close the modal and refresh the file', async () => {
    await flushSuppliers([buildSupplier()]);
    openModal();

    fillForm();
    submitForm();

    const request = httpMock.expectOne(isSuppliersRequest);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      name: 'Quincaillerie Centrale',
      contact_person: 'Fatou Ndiaye',
      phone: '+221 70 111 22 33',
      email: 'commercial@quincaillerie-centrale.sn',
      address: 'Dakar, Rufisque',
    });

    request.flush({ data: buildSupplier({ id: 9, name: 'Quincaillerie Centrale' }) });

    await settle();

    // La modale est fermee et un toast de succes est publie.
    expect(dialog()).toBeNull();
    expect(toastService.toasts()).toHaveLength(1);
    expect(toastService.toasts()[0].type).toBe('success');
    expect(toastService.toasts()[0].message).toContain('Quincaillerie Centrale');

    // Le fichier fournisseurs est recharge.
    httpMock
      .expectOne(isSuppliersRequest)
      .flush(
        buildPage([buildSupplier(), buildSupplier({ id: 9, name: 'Quincaillerie Centrale' })]),
      );

    await settle();

    expect(rows()).toHaveLength(2);
  });

  it('should display the server error when the creation fails', async () => {
    await flushSuppliers([buildSupplier()]);
    openModal();

    fillForm();
    submitForm();

    httpMock
      .expectOne(isSuppliersRequest)
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
