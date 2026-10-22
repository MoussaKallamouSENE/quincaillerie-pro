import { registerLocaleData } from '@angular/common';
import { provideHttpClient, HttpRequest } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { LOCALE_ID } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import localeFr from '@angular/common/locales/fr';
import { ToastService } from '../../core/toast/toast-service';
import type { Product, ProductsPage } from '../../services/product.model';
import { ProductList } from './product-list';

// L'application s'execute en francais (`app.config.ts`) : les specs doivent
// reproduire cette locale pour que les montants soient formates pareil.
registerLocaleData(localeFr);

function buildProduct(overrides: Partial<Product> = {}): Product {
  return {
    id: 1,
    sku: 'PRD-0001',
    name: 'Perceuse à percussion 800W',
    category: { id: 1, name: 'Outillage' },
    unit: { id: 1, name: 'Pièce', symbol: 'pce' },
    purchase_price: '45000.00',
    sale_price: '67500.00',
    stock_quantity: 40,
    min_stock_alert: 10,
    is_active: true,
    is_low_stock: false,
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

function buildPage(data: Product[]): ProductsPage {
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

/** Categories de reference, renvoyees par `GET /api/categories`. */
const CATEGORIES = {
  data: [
    { id: 1, name: 'Outillage', description: null, is_active: true },
    { id: 2, name: 'Peinture', description: null, is_active: true },
  ],
};

/** Unites de reference, renvoyees par `GET /api/units`. */
const UNITS = {
  data: [
    { id: 1, name: 'Pièce', symbol: 'pcs', description: null, is_active: true },
    { id: 2, name: 'Kilogramme', symbol: 'kg', description: null, is_active: true },
  ],
};

describe('ProductList', () => {
  let fixture: ComponentFixture<ProductList>;
  let httpMock: HttpTestingController;
  let toastService: ToastService;

  /** Requete du catalogue produits. */
  const isCatalogRequest = (request: HttpRequest<unknown>) => request.url.endsWith('/api/products');

  /** Requete de la liste des categories. */
  const isCategoriesRequest = (request: HttpRequest<unknown>) =>
    request.url.endsWith('/api/categories');

  /** Requete de la liste des unites. */
  const isUnitsRequest = (request: HttpRequest<unknown>) => request.url.endsWith('/api/units');

  /** Repond au catalogue et stabilise la vue. */
  async function flushProducts(products: Product[]): Promise<void> {
    httpMock.expectOne(isCatalogRequest).flush(buildPage(products));

    await fixture.whenStable();
    fixture.detectChanges();
  }

  /** Repond aux listes de reference et stabilise la vue. */
  async function flushReferences(): Promise<void> {
    httpMock.expectOne(isCategoriesRequest).flush(CATEGORIES);
    httpMock.expectOne(isUnitsRequest).flush(UNITS);

    await fixture.whenStable();
    fixture.detectChanges();
  }

  /**
   * Laisse les reponses HTTP et les signaux se propager.
   *
   * `fixture.whenStable()` ne peut pas etre utilise ici : le rechargement du
   * catalogue laisse une requete en attente, qui bloquerait l'attente.
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

  /** Lignes du tableau des produits. */
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
    ).find((candidate) => candidate.textContent?.trim().startsWith('Nouveau Produit'));

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

  /** Choisit une option dans une liste deroulante. */
  function selectField(selector: string, label: string): void {
    const select = (fixture.nativeElement as HTMLElement).querySelector(
      selector,
    ) as HTMLSelectElement;
    const option = Array.from(select.options).find((candidate) =>
      candidate.textContent?.trim().includes(label),
    );

    select.value = option?.value ?? '';
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();
  }

  /** Remplit le formulaire avec un produit valide. */
  function fillForm(): void {
    setField('#product-name', 'Marteau 500 g');
    setField('#product-sku', 'PRD-0006');
    selectField('#product-category', 'Outillage');
    selectField('#product-unit', 'Pièce');
    setField('#product-purchase-price', '3500');
    setField('#product-sale-price', '5500');
    setField('#product-stock', '120');
    setField('#product-alert', '20');
  }

  /** Soumet le formulaire de creation. */
  function submitForm(): void {
    const form = (fixture.nativeElement as HTMLElement).querySelector('form') as HTMLFormElement;

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ProductList],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: LOCALE_ID, useValue: 'fr-FR' },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(ProductList);
    httpMock = TestBed.inject(HttpTestingController);
    toastService = TestBed.inject(ToastService);

    // Rendu initial et execution des effets : la requete du catalogue est
    // emise par la ressource de la page.
    fixture.detectChanges();
  });

  afterEach(() => {
    for (const request of httpMock.match(isCategoriesRequest)) {
      request.flush({ data: [] });
    }

    for (const request of httpMock.match(isUnitsRequest)) {
      request.flush({ data: [] });
    }

    for (const request of httpMock.match(isCatalogRequest)) {
      request.flush(request.request.method === 'POST' ? { data: buildProduct() } : buildPage([]));
    }
  });

  it('should create', () => {
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('should announce the loading of the catalogue', () => {
    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Chargement du catalogue');
  });

  it('should render the products returned by the API', async () => {
    await flushProducts([
      buildProduct(),
      buildProduct({
        id: 2,
        sku: 'PRD-0004',
        name: 'Écharpe de câbles 5 m',
        category: { id: 2, name: 'Électricité' },
        purchase_price: '3500.00',
        sale_price: '5500.00',
        stock_quantity: 12,
      }),
    ]);

    const element = fixture.nativeElement as HTMLElement;
    const text = normalizeSpaces(element.textContent ?? '');

    expect(rows()).toHaveLength(2);
    expect(text).toContain('PRD-0001');
    expect(text).toContain('Perceuse à percussion 800W');
    expect(text).toContain('Outillage');
    expect(text).toContain('Électricité');
    expect(text).toContain('45 000,00');
    expect(text).toContain('67 500,00');
    expect(text).toContain('40');
  });

  it('should display the stock in red when it reaches the alert threshold', async () => {
    await flushProducts([
      buildProduct({ id: 1, sku: 'PRD-0001', stock_quantity: 4, is_low_stock: true }),
      buildProduct({ id: 2, sku: 'PRD-0002', stock_quantity: 40, is_low_stock: false }),
    ]);

    const [lowStockRow, healthyRow] = rows();

    expect(lowStockRow.querySelector('.text-red-400')?.textContent).toContain('4');
    expect(healthyRow.querySelector('.text-red-400')).toBeNull();
  });

  it('should filter the products by name', async () => {
    await flushProducts([
      buildProduct({ id: 1, sku: 'PRD-0001', name: 'Perceuse à percussion 800W' }),
      buildProduct({ id: 2, sku: 'PRD-0004', name: 'Écharpe de câbles 5 m' }),
    ]);

    search('perceuse');

    expect(rows()).toHaveLength(1);
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('PRD-0001');
  });

  it('should ignore the accents when filtering', async () => {
    await flushProducts([
      buildProduct({ id: 1, sku: 'PRD-0001', name: 'Perceuse à percussion 800W' }),
      buildProduct({ id: 2, sku: 'PRD-0004', name: 'Écharpe de câbles 5 m' }),
    ]);

    search('echarpe');

    expect(rows()).toHaveLength(1);
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('PRD-0004');
  });

  it('should show an empty state when the search matches nothing', async () => {
    await flushProducts([buildProduct()]);

    search('marteau');

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Aucun produit ne correspond');
  });

  it('should show an error state and allow retrying', async () => {
    httpMock
      .expectOne(isCatalogRequest)
      .flush(
        { message: 'Erreur interne du serveur.' },
        { status: 500, statusText: 'Server Error' },
      );

    await fixture.whenStable();
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Catalogue indisponible');
    expect(element.textContent).toContain('Erreur interne du serveur.');

    const retry = Array.from(element.querySelectorAll('button')).find((button) =>
      button.textContent?.trim().startsWith('Réessayer'),
    );

    expect(retry).toBeTruthy();
    retry?.click();
    fixture.detectChanges();

    httpMock.expectOne(isCatalogRequest).flush(buildPage([buildProduct()]));

    await fixture.whenStable();
    fixture.detectChanges();

    expect(element.textContent).toContain('Perceuse à percussion 800W');
  });

  it('should display a button to create a product', () => {
    const element = fixture.nativeElement as HTMLElement;
    const button = Array.from(element.querySelectorAll('button')).find((candidate) =>
      candidate.textContent?.trim().startsWith('Nouveau Produit'),
    );

    expect(button).toBeTruthy();
  });

  it('should open the creation modal and load the reference lists', async () => {
    await flushProducts([buildProduct()]);

    expect(dialog()).toBeNull();

    openModal();

    expect(dialog()).toBeTruthy();

    await flushReferences();

    const element = fixture.nativeElement as HTMLElement;
    const options = Array.from(element.querySelectorAll('#product-category option')).map((option) =>
      option.textContent?.trim(),
    );

    expect(options).toContain('Outillage');
    expect(options).toContain('Peinture');
  });

  it('should close the modal with the cancel button', async () => {
    await flushProducts([buildProduct()]);
    openModal();
    await flushReferences();

    const cancel = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button'),
    ).find((candidate) => candidate.textContent?.trim() === 'Annuler');

    cancel?.click();
    fixture.detectChanges();

    expect(dialog()).toBeNull();
  });

  it('should focus the name field when the modal opens', async () => {
    await flushProducts([buildProduct()]);
    openModal();
    await flushReferences();

    const name = (fixture.nativeElement as HTMLElement).querySelector(
      '#product-name',
    ) as HTMLInputElement;

    expect(document.activeElement).toBe(name);
  });

  it('should not send anything when the form is incomplete', async () => {
    await flushProducts([buildProduct()]);
    openModal();
    await flushReferences();

    setField('#product-name', 'Marteau 500 g');
    submitForm();

    expect(httpMock.match(isCatalogRequest)).toHaveLength(0);

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Ce champ est obligatoire.');
    expect(dialog()).toBeTruthy();
  });

  it('should create the product, close the modal and refresh the catalogue', async () => {
    await flushProducts([buildProduct()]);
    openModal();
    await flushReferences();

    fillForm();
    submitForm();

    const request = httpMock.expectOne(isCatalogRequest);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      name: 'Marteau 500 g',
      sku: 'PRD-0006',
      category_id: 1,
      unit_id: 1,
      purchase_price: 3500,
      sale_price: 5500,
      stock_quantity: 120,
      min_stock_alert: 20,
    });

    request.flush({ data: buildProduct({ id: 9, name: 'Marteau 500 g', sku: 'PRD-0006' }) });

    await settle();

    // La modale est fermee et un toast de succes est publie.
    expect(dialog()).toBeNull();
    expect(toastService.toasts()).toHaveLength(1);
    expect(toastService.toasts()[0].type).toBe('success');
    expect(toastService.toasts()[0].message).toContain('Marteau 500 g');

    // Le catalogue est recharge.
    httpMock
      .expectOne(isCatalogRequest)
      .flush(buildPage([buildProduct(), buildProduct({ id: 9, name: 'Marteau 500 g' })]));

    await settle();

    expect(rows()).toHaveLength(2);
  });

  it('should display the server error when the creation fails', async () => {
    await flushProducts([buildProduct()]);
    openModal();
    await flushReferences();

    fillForm();
    submitForm();

    httpMock
      .expectOne(isCatalogRequest)
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
