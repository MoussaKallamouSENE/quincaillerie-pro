import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { computed, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { AuthService } from '../../../core/auth/auth-service';
import type {
  DashboardRecentSale,
  DashboardStats,
  SalesChartPoint,
} from '../../../services/dashboard.model';
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

/** Serie de sept jours, avec un pic le dernier jour. */
const CHART: SalesChartPoint[] = [
  { date: '2026-10-16', label: 'ven. 16', total_amount: 0, sales_count: 0 },
  { date: '2026-10-17', label: 'sam. 17', total_amount: 25000, sales_count: 1 },
  { date: '2026-10-18', label: 'dim. 18', total_amount: 0, sales_count: 0 },
  { date: '2026-10-19', label: 'lun. 19', total_amount: 42000, sales_count: 2 },
  { date: '2026-10-20', label: 'mar. 20', total_amount: 0, sales_count: 0 },
  { date: '2026-10-21', label: 'mer. 21', total_amount: 70000, sales_count: 3 },
  { date: '2026-10-22', label: 'jeu. 22', total_amount: 141800, sales_count: 2 },
];

/** Dernieres ventes, une par statut de reglement. */
const RECENT_SALES: DashboardRecentSale[] = [
  {
    id: 5,
    reference: 'VTE-000005',
    customer_name: 'Awa Diop',
    total_amount: 18500,
    payment_status: 'paid',
    created_at: '2026-10-22T15:00:00+00:00',
  },
  {
    id: 4,
    reference: 'VTE-000004',
    customer_name: null,
    total_amount: 4200,
    payment_status: 'partial',
    created_at: '2026-10-22T14:00:00+00:00',
  },
  {
    id: 3,
    reference: 'VTE-000003',
    customer_name: 'Boutique Samba',
    total_amount: 98500,
    payment_status: 'credit',
    created_at: '2026-10-22T13:00:00+00:00',
  },
  {
    id: 2,
    reference: 'VTE-000002',
    customer_name: 'Fatou Ndiaye',
    total_amount: 320000,
    payment_status: 'paid',
    created_at: '2026-10-22T12:00:00+00:00',
  },
  {
    id: 1,
    reference: 'VTE-000001',
    customer_name: 'Michel Kévin',
    total_amount: 67200,
    payment_status: 'paid',
    created_at: '2026-10-22T11:00:00+00:00',
  },
];

function buildStats(overrides: Partial<DashboardStats> = {}): DashboardStats {
  return {
    total_revenue_today: 141800,
    sales_count_today: 2,
    low_stock_count: 1,
    recent_sales: [],
    sales_chart: [],
    ...overrides,
  };
}

describe('Dashboard', () => {
  let fixture: ComponentFixture<Dashboard>;
  let httpMock: HttpTestingController;

  /** Roles du faux utilisateur connecte. */
  const roles = signal<string[]>([]);

  const authMock = {
    user: signal(null).asReadonly(),
    roles: roles.asReadonly(),
    permissions: signal<string[]>([]).asReadonly(),
    displayName: computed(() => 'Awa Diop'),
    initials: computed(() => 'AD'),
    isAuthenticated: computed(() => true),
    hasRole: (...allowed: string[]) => allowed.some((role) => roles().includes(role)),
    hasPermission: () => false,
  };

  /** Requete des statistiques du tableau de bord. */
  const isDashboardRequest = (request: { url: string }) => request.url.endsWith('/api/dashboard');

  /** Requete du compteur de references du catalogue. */
  const isCatalogRequest = (request: { url: string; params: { has: (key: string) => boolean } }) =>
    request.url.endsWith('/api/products') && !request.params.has('low_stock');

  /** Requete de la liste des produits sous le seuil d'alerte. */
  const isLowStockRequest = (request: {
    url: string;
    params: { get: (key: string) => string | null };
  }) => request.url.endsWith('/api/products') && request.params.get('low_stock') === '1';

  /** Cree le composant et declenche les requetes initiales. */
  function render(): void {
    fixture = TestBed.createComponent(Dashboard);
    fixture.detectChanges();
  }

  /** Repond aux trois requetes du tableau de bord et stabilise la vue. */
  async function flushData(
    options: {
      stats?: DashboardStats;
      catalog?: ProductsPage;
      lowStock?: ProductsPage;
    } = {},
  ): Promise<void> {
    httpMock.expectOne(isCatalogRequest).flush(options.catalog ?? buildPage([], 0));
    httpMock.expectOne(isLowStockRequest).flush(options.lowStock ?? buildPage([], 0));
    httpMock.expectOne(isDashboardRequest).flush(options.stats ?? buildStats());

    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    roles.set([]);
    localStorage.clear();

    await TestBed.configureTestingModule({
      imports: [Dashboard],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: AuthService, useValue: authMock },
      ],
    }).compileComponents();

    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    // Les requetes non traitees sont repondues pour liberer les taches en
    // attente des ressources.
    for (const request of httpMock.match(() => true)) {
      if (isDashboardRequest(request.request)) {
        request.flush(buildStats());
      } else {
        request.flush(buildPage([], 0));
      }
    }

    fixture?.destroy();
    localStorage.clear();
  });

  it('should create', () => {
    render();

    expect(fixture.componentInstance).toBeTruthy();
  });

  it('should render the dashboard title', () => {
    render();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Tableau de bord');
  });

  it('should render the four KPI cards for a manager', async () => {
    render();
    await flushData();

    const element = fixture.nativeElement as HTMLElement;
    const cards = element.querySelectorAll('article');

    expect(cards).toHaveLength(4);
    expect(element.textContent).toContain("Chiffre d'affaires");
    expect(element.textContent).toContain('Ventes du jour');
    expect(element.textContent).toContain('Produits en stock');
    expect(element.textContent).toContain('Alertes de stock bas');
    expect(cards[0].textContent).toContain('141');
  });

  it('should announce the loading of the stock alerts', () => {
    render();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Chargement des alertes de stock');
  });

  it('should list the stock alerts returned by the API', async () => {
    render();
    await flushData({
      catalog: buildPage([], 14),
      lowStock: buildPage(
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
    });

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
    render();
    await flushData({
      stats: buildStats({ low_stock_count: 5 }),
      catalog: buildPage([], 14),
      lowStock: buildPage([buildProduct()], 5),
    });

    const element = fixture.nativeElement as HTMLElement;
    const cards = Array.from(element.querySelectorAll('article'));

    expect(cards[2].textContent).toContain('14');
    expect(cards[3].textContent).toContain('5');
  });

  it('should show an empty state when no product is below its threshold', async () => {
    render();
    await flushData({ catalog: buildPage([], 14) });

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Aucune alerte de stock');
  });

  it('should show an error state and allow retrying the stock alerts', async () => {
    render();

    httpMock.expectOne(isCatalogRequest).flush(buildPage([], 14));
    httpMock.expectOne(isDashboardRequest).flush(buildStats());
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

  it('should draw the chart from the API sales', async () => {
    render();
    await flushData({ stats: buildStats({ sales_chart: CHART }) });

    const element = fixture.nativeElement as HTMLElement;
    const chart = element.querySelector('[role="list"]');
    const bars = chart?.querySelectorAll('.bg-emerald-500') ?? [];

    expect(chart).toBeTruthy();
    expect(bars).toHaveLength(7);
    expect(element.textContent).toContain('ven. 16');
    expect(element.textContent).toContain('jeu. 22');

    const items = chart?.querySelectorAll('[role="listitem"]') ?? [];
    expect(items[6].getAttribute('aria-label')).toBe('jeu. 22 : 141800 FCFA');
    expect((bars[6] as HTMLElement).style.height).toBe('100%');
    expect((bars[0] as HTMLElement).style.height).toBe('0%');
  });

  it('should render the five latest sales with their statuses', async () => {
    render();
    await flushData({ stats: buildStats({ recent_sales: RECENT_SALES }) });

    const element = fixture.nativeElement as HTMLElement;
    const rows = element.querySelectorAll('tbody tr');

    expect(rows).toHaveLength(5);
    expect(element.textContent).toContain('VTE-000005');
    expect(element.textContent).toContain('Awa Diop');
    expect(element.textContent).toContain('Passage');
    expect(element.textContent).toContain('Payé');
    expect(element.textContent).toContain('Partiel');
    expect(element.textContent).toContain('Crédit');
  });

  it('should hide the revenue card and the chart from an employee', async () => {
    roles.set(['Employé']);
    render();

    await flushData({
      stats: buildStats({
        total_revenue_today: 0,
        sales_count_today: 3,
        low_stock_count: 2,
        sales_chart: [],
        recent_sales: RECENT_SALES.slice(0, 1),
      }),
    });

    const element = fixture.nativeElement as HTMLElement;
    const cards = element.querySelectorAll('article');

    expect(cards).toHaveLength(3);
    expect(element.textContent).not.toContain("Chiffre d'affaires");
    expect(element.textContent).not.toContain('Évolution des ventes');
    expect(element.textContent).toContain('Bonjour Awa Diop');
    expect(element.textContent).toContain('Ventes enregistrées');
    expect(element.textContent).toContain('3');
    expect(element.textContent).toContain('réservés aux responsables');
    // Les dernières ventes restent visibles pour l'employé.
    expect(element.querySelectorAll('tbody tr')).toHaveLength(1);
  });

  it('should recognise the employee role without its accent', async () => {
    roles.set(['employe']);
    render();
    await flushData();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).not.toContain("Chiffre d'affaires");
    expect(element.textContent).toContain('Ventes enregistrées');
  });

  it('should show an error state and allow retrying the statistics', async () => {
    render();

    httpMock.expectOne(isCatalogRequest).flush(buildPage([], 14));
    httpMock.expectOne(isLowStockRequest).flush(buildPage([], 0));
    httpMock
      .expectOne(isDashboardRequest)
      .flush({ message: 'Service indisponible.' }, { status: 500, statusText: 'Server Error' });

    await fixture.whenStable();
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Statistiques indisponibles');
    expect(element.textContent).toContain('Service indisponible.');

    const retry = Array.from(element.querySelectorAll('button')).find((button) =>
      button.textContent?.trim().startsWith('Réessayer'),
    );

    expect(retry).toBeTruthy();
    retry?.click();
    fixture.detectChanges();

    httpMock.expectOne(isDashboardRequest).flush(buildStats());

    await fixture.whenStable();
    fixture.detectChanges();

    expect(element.textContent).not.toContain('Statistiques indisponibles');
    expect(element.querySelectorAll('article')).toHaveLength(4);
  });
});
