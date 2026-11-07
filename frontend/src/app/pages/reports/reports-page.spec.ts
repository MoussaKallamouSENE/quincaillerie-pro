import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { WritableSignal } from '@angular/core';
import { Subject, of, throwError } from 'rxjs';
import { vi } from 'vitest';

import { ReportsPage } from './reports-page';
import {
  REPORT_TYPES,
  ReportPage,
  ReportPeriod,
  ReportService,
  ReportSummary,
  ReportType,
} from '../../services/report-service';
import { ToastService } from '../../core/toast/toast-service';
import { REPORT_COLUMNS } from './report-columns';

type TestReportsPage = {
  period: WritableSignal<ReportPeriod>;
  from: WritableSignal<string>;
  to: WritableSignal<string>;
  searchTerm: WritableSignal<string>;
  appliedSearch: WritableSignal<string>;
  selectedType: WritableSignal<ReportType>;
  summary: WritableSignal<ReportSummary | null>;
  page: WritableSignal<ReportPage | null>;
  loading: WritableSignal<boolean>;
  exporting: WritableSignal<boolean>;
  load: (pageNumber?: number) => void;
  changePage: (pageNumber: number) => void;
  onPeriodChange: (period: ReportPeriod) => void;
  onTypeChange: (type: ReportType) => void;
  onSearchChange: (value: string) => void;
  applyFilters: () => void;
  clearSearch: () => void;
  resetFilters: () => void;
  exportPdf: () => void;
  print: () => void;
};

describe('ReportsPage', () => {
  let fixture: ComponentFixture<ReportsPage>;
  let reportService: {
    summary: ReturnType<typeof vi.fn>;
    report: ReturnType<typeof vi.fn>;
    pdf: ReturnType<typeof vi.fn>;
  };
  let toastService: {
    success: ReturnType<typeof vi.fn>;
    error: ReturnType<typeof vi.fn>;
  };

  const summary: ReportSummary = {
    period: { from: '2026-11-01', to: '2026-11-03' },
    currency: 'XOF',
    metrics: {
      revenue: 150000,
      sales_count: 12,
      received_purchases: 50000,
      received_purchases_count: 3,
      expenses: 10000,
      profit: null,
      margin: null,
    },
    activity: [
      { bucket: '2026-11-01', amount: 50000, count: 4 },
      { bucket: '2026-11-03', amount: 100000, count: 8 },
    ],
    top_products: [{ id: 1, name: 'Produit test', sku: 'TEST-001', quantity: 5, revenue: 150000 }],
    notes: ['Note de test'],
  };

  const page: ReportPage = {
    type: 'sales',
    data: [{ id: 1, reference: 'VTE-001', total_amount: 150000 }],
    meta: { current_page: 1, last_page: 3, per_page: 25, total: 60 },
  };

  function createPage(): void {
    fixture = TestBed.createComponent(ReportsPage);
    fixture.detectChanges();
  }

  function component(): TestReportsPage {
    return fixture.componentInstance as unknown as TestReportsPage;
  }

  function buttonByText(label: string): HTMLButtonElement {
    const button = Array.from(
      fixture.nativeElement.querySelectorAll('button') as NodeListOf<HTMLButtonElement>,
    ).find((candidate) => candidate.textContent?.replace(/\s+/g, ' ').trim().includes(label));

    if (!button) {
      throw new Error(`Bouton introuvable : ${label}`);
    }

    return button;
  }

  function applyCustomRange(from: string, to: string): void {
    const instance = component();

    instance.period.set('custom');
    instance.from.set(from);
    instance.to.set(to);
  }

  beforeEach(async () => {
    reportService = {
      summary: vi.fn().mockReturnValue(of(summary)),
      report: vi.fn().mockReturnValue(of(page)),
      pdf: vi.fn(),
    };

    toastService = {
      success: vi.fn(),
      error: vi.fn(),
    };

    await TestBed.configureTestingModule({
      imports: [ReportsPage],
      providers: [
        { provide: ReportService, useValue: reportService },
        { provide: ToastService, useValue: toastService },
      ],
    }).compileComponents();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('charge la synthèse et le rapport au démarrage', () => {
    createPage();

    expect(reportService.summary).toHaveBeenCalledOnce();
    expect(reportService.report).toHaveBeenCalledOnce();
    expect(reportService.summary).toHaveBeenCalledWith({
      period: 'month',
      search: '',
      page: 1,
      per_page: 25,
    });
    expect(reportService.report).toHaveBeenCalledWith('sales', {
      period: 'month',
      search: '',
      page: 1,
      per_page: 25,
    });
  });

  it('affiche les données de synthèse reçues', () => {
    createPage();

    const content = fixture.nativeElement.textContent as string;

    expect(content).toContain('150');
    expect(content).toContain('Produit test');
    expect(content).toContain('Note de test');
    expect(content).toContain('Chiffre d’affaires');
  });

  it('affiche le message lorsque le chargement du rapport échoue', () => {
    reportService.report.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 500,
            error: { message: 'Erreur de chargement du rapport.' },
          }),
      ),
    );

    createPage();

    expect(fixture.nativeElement.textContent).toContain('Erreur de chargement du rapport.');
  });

  it('affiche le message lorsque la synthèse échoue mais conserve le rapport', () => {
    reportService.summary.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 500,
            error: { message: 'Synthèse indisponible.' },
          }),
      ),
    );

    createPage();

    const content = fixture.nativeElement.textContent as string;

    expect(content).toContain('Synthèse indisponible.');
    expect(content).toContain('VTE-001');
  });

  it('recharge le rapport lors du changement de période prédéfinie', () => {
    createPage();

    const instance = component();
    reportService.summary.mockClear();
    reportService.report.mockClear();

    instance.onPeriodChange('week');

    expect(reportService.summary).toHaveBeenLastCalledWith({
      period: 'week',
      search: '',
      page: 1,
      per_page: 25,
    });
    expect(reportService.report).toHaveBeenLastCalledWith('sales', {
      period: 'week',
      search: '',
      page: 1,
      per_page: 25,
    });
  });

  it('refuse une période personnalisée avec des dates invalides', () => {
    createPage();

    reportService.summary.mockClear();
    reportService.report.mockClear();

    applyCustomRange('2026-11-10', '2026-11-01');
    component().load();
    fixture.detectChanges();

    expect(reportService.summary).not.toHaveBeenCalled();
    expect(reportService.report).not.toHaveBeenCalled();
    expect(fixture.nativeElement.textContent).toContain(
      'La date de début doit précéder ou être égale à la date de fin.',
    );
  });

  it('signale une période invalide lors de l’application des filtres', () => {
    createPage();

    const instance = component();
    reportService.report.mockClear();

    applyCustomRange('', '2026-11-03');
    instance.applyFilters();

    expect(reportService.report).not.toHaveBeenCalled();
    expect(toastService.error).toHaveBeenCalledWith(
      'Renseignez la date de début et la date de fin.',
    );
  });

  it('désactive le type de rapport et la pagination pendant une période invalide', async () => {
    createPage();

    const instance = component();
    reportService.report.mockClear();

    applyCustomRange('2026-11-10', '2026-11-01');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    const typeSelect = fixture.nativeElement.querySelector(
      'select[name="type"]',
    ) as HTMLSelectElement;

    expect(typeSelect.disabled).toBe(true);
    expect(buttonByText('Suivant').disabled).toBe(true);

    instance.onTypeChange('expenses');

    expect(instance.selectedType()).toBe('sales');
    expect(reportService.report).not.toHaveBeenCalled();
  });

  it('réinitialise la période, les dates et la recherche via le bouton dédié', () => {
    createPage();

    const instance = component();
    instance.selectedType.set('expenses');
    instance.period.set('week');
    instance.from.set('2026-11-01');
    instance.to.set('2026-11-03');
    instance.onSearchChange('awa');
    fixture.detectChanges();

    const resetButton = buttonByText('Réinitialiser les filtres');

    expect(resetButton.disabled).toBe(false);

    reportService.report.mockClear();
    resetButton.click();
    fixture.detectChanges();

    expect(instance.period()).toBe('month');
    expect(instance.from()).toBe('');
    expect(instance.to()).toBe('');
    expect(instance.searchTerm()).toBe('');
    expect(instance.appliedSearch()).toBe('');
    expect(instance.selectedType()).toBe('expenses');
    expect(reportService.report).toHaveBeenLastCalledWith('expenses', {
      period: 'month',
      search: '',
      page: 1,
      per_page: 25,
    });
  });

  it('transmet une période personnalisée valide', () => {
    createPage();

    reportService.report.mockClear();

    applyCustomRange('2026-11-01', '2026-11-03');
    component().load();

    expect(reportService.report).toHaveBeenLastCalledWith('sales', {
      period: 'custom',
      from: '2026-11-01',
      to: '2026-11-03',
      search: '',
      page: 1,
      per_page: 25,
    });
  });

  it('applique la recherche après le délai de stabilisation', () => {
    createPage();
    vi.useFakeTimers();

    const instance = component();
    reportService.report.mockClear();

    instance.onSearchChange('ciment');
    fixture.detectChanges();

    vi.advanceTimersByTime(349);

    expect(reportService.report).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1);

    expect(reportService.report).toHaveBeenLastCalledWith('sales', {
      period: 'month',
      search: 'ciment',
      page: 1,
      per_page: 25,
    });
  });

  it('applique et efface la recherche immédiatement', () => {
    createPage();

    const instance = component();
    reportService.report.mockClear();

    instance.onSearchChange('  VTE-001  ');
    instance.applyFilters();

    expect(reportService.report).toHaveBeenLastCalledWith('sales', {
      period: 'month',
      search: 'VTE-001',
      page: 1,
      per_page: 25,
    });

    reportService.report.mockClear();
    instance.clearSearch();

    expect(reportService.report).toHaveBeenLastCalledWith('sales', {
      period: 'month',
      search: '',
      page: 1,
      per_page: 25,
    });
  });

  it('charge le rapport sélectionné lors du changement de type', () => {
    createPage();

    const instance = component();
    reportService.report.mockClear();

    instance.onTypeChange('expenses');
    fixture.detectChanges();

    expect(reportService.report).toHaveBeenLastCalledWith('expenses', {
      period: 'month',
      search: '',
      page: 1,
      per_page: 25,
    });
    expect(instance.selectedType()).toBe('expenses');
    expect(fixture.nativeElement.textContent).toContain('Dépenses');
  });

  REPORT_TYPES.forEach((option) => {
    it(`affiche les colonnes du rapport ${option.label}`, () => {
      reportService.report.mockReturnValue(
        of({
          type: option.id,
          data: [{ id: 1 }],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        }),
      );

      createPage();

      component().onTypeChange(option.id);
      fixture.detectChanges();

      const headers = Array.from(
        fixture.nativeElement.querySelectorAll('th') as NodeListOf<HTMLTableCellElement>,
      ).map((header) => header.textContent?.trim());

      expect(headers).toEqual(REPORT_COLUMNS[option.id].map((column) => column.label));
    });
  });

  it('charge la page demandée lors du changement de pagination', () => {
    createPage();

    reportService.report.mockClear();

    component().changePage(2);

    expect(reportService.report).toHaveBeenCalledWith('sales', {
      period: 'month',
      search: '',
      page: 2,
      per_page: 25,
    });
  });

  it('ne charge pas une page située hors des limites', () => {
    createPage();

    reportService.report.mockClear();

    component().changePage(4);

    expect(reportService.report).not.toHaveBeenCalled();
  });

  it('ignore la réponse d’une requête remplacée par une plus récente', () => {
    createPage();

    const instance = component();
    const staleSummary = new Subject<ReportSummary>();
    const staleReport = new Subject<ReportPage>();
    const latestPage: ReportPage = {
      ...page,
      meta: { ...page.meta, current_page: 3 },
    };

    reportService.summary.mockReturnValueOnce(staleSummary);
    reportService.report.mockReturnValueOnce(staleReport);

    instance.load(2);

    reportService.summary.mockReturnValueOnce(of(summary));
    reportService.report.mockReturnValueOnce(of(latestPage));

    instance.load(3);

    staleSummary.next(summary);
    staleReport.next(page);
    staleSummary.complete();
    staleReport.complete();

    fixture.detectChanges();

    expect(instance.page()?.meta.current_page).toBe(3);
  });

  it('imprime la page quand le rapport contient des données', () => {
    const print = vi.spyOn(window, 'print').mockImplementation(() => undefined);

    createPage();
    component().print();

    expect(print).toHaveBeenCalledOnce();
    print.mockRestore();
  });

  it('autorise l’impression de la synthèse même sans lignes détaillées', () => {
    reportService.report.mockReturnValue(
      of({
        ...page,
        data: [],
        meta: { ...page.meta, last_page: 1, total: 0 },
      }),
    );

    createPage();
    fixture.detectChanges();

    expect(buttonByText('Imprimer').disabled).toBe(false);
  });

  it('refuse l’impression avec une période personnalisée invalide', () => {
    const print = vi.spyOn(window, 'print').mockImplementation(() => undefined);

    createPage();
    applyCustomRange('2026-11-10', '2026-11-01');
    component().print();

    expect(print).not.toHaveBeenCalled();
    expect(toastService.error).toHaveBeenCalledWith(
      'La date de début doit précéder ou être égale à la date de fin.',
    );
    print.mockRestore();
  });

  it('exporte le rapport PDF avec les filtres courants', () => {
    const createObjectURL = vi.fn().mockReturnValue('blob:rapport');
    const revokeObjectURL = vi.fn();
    const click = vi
      .spyOn(HTMLAnchorElement.prototype, 'click')
      .mockImplementation(() => undefined);

    Object.defineProperty(URL, 'createObjectURL', {
      configurable: true,
      value: createObjectURL,
    });
    Object.defineProperty(URL, 'revokeObjectURL', {
      configurable: true,
      value: revokeObjectURL,
    });

    reportService.pdf.mockReturnValue(of(new Blob(['%PDF-1.4'], { type: 'application/pdf' })));

    createPage();
    component().exportPdf();

    expect(reportService.pdf).toHaveBeenCalledWith('sales', {
      period: 'month',
      search: '',
      page: 1,
      per_page: 25,
    });
    expect(createObjectURL).toHaveBeenCalledOnce();
    expect(click).toHaveBeenCalledOnce();
    expect(revokeObjectURL).toHaveBeenCalledOnce();
    expect(toastService.success).toHaveBeenCalledWith('Le rapport PDF a été téléchargé.');

    click.mockRestore();
  });

  it('refuse l’export PDF avec une période personnalisée incomplète', () => {
    createPage();

    const instance = component();
    reportService.pdf.mockClear();

    applyCustomRange('', '2026-11-03');
    instance.exportPdf();

    expect(reportService.pdf).not.toHaveBeenCalled();
    expect(toastService.error).toHaveBeenCalledWith(
      'Renseignez la date de début et la date de fin.',
    );
  });

  it('affiche le message JSON renvoyé par l’API lors d’un échec d’export PDF', async () => {
    const body = new Blob([JSON.stringify({ message: 'Export indisponible.' })], {
      type: 'application/json',
    });

    reportService.pdf.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 500,
            error: body,
          }),
      ),
    );

    createPage();
    component().exportPdf();

    await vi.waitFor(() => {
      expect(toastService.error).toHaveBeenCalledWith('Export indisponible.');
    });
  });
});
