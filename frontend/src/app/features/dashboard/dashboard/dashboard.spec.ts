import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import type { Product, ProductsPage } from '../../../services/product.model';
import { Dashboard } from './dashboard';

function buildProduct(overrides: Partial<Product> = {}): Product {
  return {
    id: 1,
    sku: 'PRD-0001',
    name: 'Perceuse à percussion 800W',
    category: { id: 1, name: 'Outillage' },
    unit: { id: 1, name: 'Pièce', symbol: 'pce' },
    purchase_price: '45000.00',
    sale_price: '67500.00',
    stock_quantity: 4,
    min_stock_alert: 10,
    is_active: true,
    is_low_stock: true,
    ...overrides,
  };
}

function buildPage(data: Product[], total: number): ProductsPage {
  return {
    data,
    meta: {
      current_page: 1,
      from: data.length ? 1 : null,
      last_page: 1,
      per_page: 10,
      to: data.length ? data.length : null,
      total,
    },
  };
}

describe('Dashboard', () => {
  let fixture: ComponentFixture<Dashboard>;
  let httpMock: HttpTestingController;

  /** Requete du compteur de references du catalogue. */
  const isCatalogRequest = (request: { url: string; params: { has: (key: string) => boolean } }) =>
    request.url.endsWith('/api/products') && !request.params.has('low_stock');

  /** Requete de la liste des produits sous le seuil d'alerte. */
  const isLowStockRequest = (request: {
    url: string;
    params: { get: (key: string) => string | null };
  }) => request.url.endsWith('/api/products') && request.params.get('low_stock') === '1';

  /** Repond aux deux requetes produits et stabilise la vue. */
  async function flushProducts(catalog: ProductsPage, lowStock: ProductsPage): Promise<void> {
    httpMock.expectOne(isCatalogRequest).flush(catalog);
    httpMock.expectOne(isLowStockRequest).flush(lowStock);

    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    localStorage.clear();

    await TestBed.configureTestingModule({
      imports: [Dashboard],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();

    fixture = TestBed.createComponent(Dashboard);
    httpMock = TestBed.inject(HttpTestingController);

    // Rendu initial et execution des effets : les deux requetes produits
    // sont emises par les ressources du tableau de bord.
    fixture.detectChanges();
  });

  afterEach(() => {
    // Les requetes non traitees sont repondues pour liberer les taches en
    // attente des ressources.
    for (const request of httpMock.match(() => true)) {
      request.flush(buildPage([], 0));
    }

    localStorage.clear();
  });

  it('should create', () => {
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('should render the dashboard title', () => {
    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Tableau de bord');
  });

  it('should render the four KPI cards', () => {
    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain("Chiffre d'affaires");
    expect(element.textContent).toContain('Ventes du jour');
    expect(element.textContent).toContain('Produits en stock');
    expect(element.textContent).toContain('Alertes de stock bas');
  });

  it('should announce the loading of the stock alerts', () => {
    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Chargement des alertes de stock');
  });

  it('should list the stock alerts returned by the API', async () => {
    await flushProducts(
      buildPage([], 14),
      buildPage(
        [
          buildProduct(),
          buildProduct({
            id: 2,
            sku: 'PRD-0004',
            name: 'Écharpe de câbles 5 m',
            category: { id: 2, name: 'Électricité' },
            stock_quantity: 2,
            min_stock_alert: 8,
          }),
        ],
        5,
      ),
    );

    const element = fixture.nativeElement as HTMLElement;
    const buttons = Array.from(element.querySelectorAll('button')).filter((button) =>
      button.textContent?.trim().startsWith('Commander'),
    );

    expect(buttons).toHaveLength(2);
    expect(element.textContent).toContain('Perceuse à percussion 800W');
    expect(element.textContent).toContain('PRD-0004');
    expect(element.textContent).toContain('4 restants');
    expect(element.textContent).toContain('Seuil : 10 pièce');
  });

  it('should show the catalogue size and the number of alerts', async () => {
    await flushProducts(buildPage([], 14), buildPage([buildProduct()], 5));

    const element = fixture.nativeElement as HTMLElement;
    const cards = Array.from(element.querySelectorAll('article'));

    expect(cards[2].textContent).toContain('14');
    expect(cards[3].textContent).toContain('5');
  });

  it('should show an empty state when no product is below its threshold', async () => {
    await flushProducts(buildPage([], 14), buildPage([], 0));

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Aucune alerte de stock');
  });

  it('should show an error state and allow retrying', async () => {
    httpMock.expectOne(isCatalogRequest).flush(buildPage([], 14));
    httpMock
      .expectOne(isLowStockRequest)
      .flush(
        { message: 'Erreur interne du serveur.' },
        { status: 500, statusText: 'Server Error' },
      );

    await fixture.whenStable();
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Alertes indisponibles');
    expect(element.textContent).toContain('Erreur interne du serveur.');

    const retry = Array.from(element.querySelectorAll('button')).find((button) =>
      button.textContent?.trim().startsWith('Réessayer'),
    );

    expect(retry).toBeTruthy();
    retry?.click();
    fixture.detectChanges();

    httpMock.expectOne(isLowStockRequest).flush(buildPage([buildProduct()], 1));

    await fixture.whenStable();
    fixture.detectChanges();

    expect(element.textContent).toContain('Perceuse à percussion 800W');
  });

  it('should render the five latest sales with their status', () => {
    const element = fixture.nativeElement as HTMLElement;
    const rows = element.querySelectorAll('tbody tr');

    expect(rows).toHaveLength(5);
    expect(element.textContent).toContain('VTE-1042');
    expect(element.textContent).toContain('Payé');
    expect(element.textContent).toContain('En attente');
  });
});
