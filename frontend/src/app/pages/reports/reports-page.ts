import { HttpErrorResponse } from '@angular/common/http';
import {
  Component,
  DestroyRef,
  ElementRef,
  computed,
  effect,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { catchError, finalize, forkJoin, map, of } from 'rxjs';
import { extractErrorMessage } from '../../core/http/api-error';
import { downloadBlob } from '../../core/http/download-file';
import { ToastService } from '../../core/toast/toast-service';
import {
  REPORT_TYPES,
  ReportFilters,
  ReportPage,
  ReportPeriod,
  ReportRow,
  ReportService,
  ReportSummary,
  ReportType,
} from '../../services/report-service';
import { REPORT_COLUMNS, fieldValue, formatCell, formatDate, formatMoney } from './report-columns';

const STATUS_BADGE_CLASSES = {
  success: 'bg-emerald-500/10 text-emerald-300 ring-emerald-500/20',
  warning: 'bg-amber-500/10 text-amber-300 ring-amber-500/20',
  danger: 'bg-red-500/10 text-red-300 ring-red-500/20',
  info: 'bg-emerald-500/10 text-emerald-300 ring-emerald-500/20',
  neutral: 'bg-slate-500/15 text-slate-300 ring-slate-500/30',
} as const;

/** Nombre de lignes demandees par page. */
const PER_PAGE = 25;

/** Delai de stabilisation de la recherche, en millisecondes. */
const SEARCH_DELAY = 350;

interface ChartPoint {
  readonly bucket: string;
  readonly label: string;
  readonly amount: number;
  readonly count: number;
  readonly x: number;
  readonly y: number;
  readonly width: number;
  readonly height: number;
  readonly valueLabel: string;
  readonly tooltip: string;
}

interface ChartTick {
  readonly y: number;
  readonly label: string;
}

interface ChartModel {
  readonly width: number;
  readonly height: number;
  readonly left: number;
  readonly right: number;
  readonly top: number;
  readonly bottom: number;
  readonly plotBottom: number;
  readonly labelY: number;
  readonly points: readonly ChartPoint[];
  readonly ticks: readonly ChartTick[];
}

type LoadResult<T> =
  { readonly ok: true; readonly value: T } | { readonly ok: false; readonly error: string };

@Component({
  selector: 'app-reports-page',
  imports: [FormsModule],
  templateUrl: './reports-page.html',
  styleUrl: './reports-page.css',
})
export class ReportsPage {
  private readonly api = inject(ReportService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly toast = inject(ToastService);

  protected readonly types = REPORT_TYPES;
  protected readonly periods: ReadonlyArray<{ id: ReportPeriod; label: string }> = [
    { id: 'today', label: "Aujourd'hui" },
    { id: 'week', label: 'Cette semaine' },
    { id: 'month', label: 'Ce mois' },
    { id: 'year', label: 'Cette année' },
    { id: 'custom', label: 'Personnalisée' },
  ];

  protected readonly period = signal<ReportPeriod>('month');
  protected readonly from = signal('');
  protected readonly to = signal('');
  protected readonly searchTerm = signal('');
  protected readonly appliedSearch = signal('');
  protected readonly selectedType = signal<ReportType>('sales');

  protected readonly summary = signal<ReportSummary | null>(null);
  protected readonly page = signal<ReportPage | null>(null);
  protected readonly loading = signal(false);
  protected readonly exporting = signal(false);
  protected readonly summaryError = signal<string | null>(null);
  protected readonly reportError = signal<string | null>(null);

  private readonly chartContainer = viewChild<ElementRef<HTMLDivElement>>('chartContainer');
  private readonly chartWidth = signal(680);
  private requestVersion = 0;

  protected readonly error = computed(() => this.reportError() ?? this.summaryError());

  protected readonly selectedLabel = computed(
    () => this.types.find((type) => type.id === this.selectedType())?.label ?? 'Rapport',
  );

  protected readonly columns = computed(() => REPORT_COLUMNS[this.selectedType()]);

  protected readonly tableMinWidth = computed(() => Math.max(720, this.columns().length * 136));

  protected readonly rows = computed<readonly ReportRow[]>(() => this.page()?.data ?? []);

  protected readonly meta = computed(() => this.page()?.meta);

  protected readonly hasPreviousPage = computed(() => (this.meta()?.current_page ?? 1) > 1);

  protected readonly hasNextPage = computed(
    () => (this.meta()?.current_page ?? 1) < (this.meta()?.last_page ?? 1),
  );

  protected readonly hasResults = computed(() => this.rows().length > 0);

  protected readonly hasActiveSearch = computed(() => this.appliedSearch().trim() !== '');

  protected readonly hasActiveFilters = computed(
    () =>
      this.period() !== 'month' ||
      this.from() !== '' ||
      this.to() !== '' ||
      this.searchTerm().trim() !== '' ||
      this.appliedSearch().trim() !== '',
  );

  protected readonly customRangeError = computed<string | null>(() => {
    if (this.period() !== 'custom') {
      return null;
    }

    const from = this.from();
    const to = this.to();

    if (!from || !to) {
      return 'Renseignez la date de début et la date de fin.';
    }

    if (from > to) {
      return 'La date de début doit précéder ou être égale à la date de fin.';
    }

    return null;
  });

  protected readonly customRangeInvalid = computed(() => this.customRangeError() !== null);

  protected readonly periodLabel = computed(() => {
    const period = this.summary()?.period;

    if (!period) {
      return '';
    }

    return `du ${formatDate(period.from)} au ${formatDate(period.to)}`;
  });

  protected readonly topProducts = computed(() => {
    const products = this.summary()?.top_products ?? [];
    const max = Math.max(1, ...products.map((product) => product.quantity));

    return products.map((product, index) => ({
      ...product,
      rank: index + 1,
      share: Math.round((product.quantity / max) * 100),
    }));
  });

  protected readonly chart = computed<ChartModel>(() => {
    const activity = this.summary()?.activity ?? [];
    const amounts = activity.map((point) => Math.max(0, Number(point.amount) || 0));
    const max = Math.max(1, ...amounts);
    const height = 280;
    const padding = { top: 28, right: 20, bottom: 44, left: 70 };
    const width = Math.max(this.chartWidth(), padding.left + padding.right + activity.length * 52);
    const plotHeight = height - padding.top - padding.bottom;
    const step = activity.length > 0 ? (width - padding.left - padding.right) / activity.length : 0;
    const barWidth = Math.min(38, Math.max(12, step * 0.56));

    const points = activity.map((point, index) => {
      const amount = amounts[index] ?? 0;
      const barHeight = (amount / max) * plotHeight;
      const center = padding.left + step * (index + 0.5);

      return {
        bucket: point.bucket,
        label: bucketLabel(point.bucket),
        amount,
        count: Number(point.count) || 0,
        x: center - barWidth / 2,
        y: padding.top + plotHeight - barHeight,
        width: barWidth,
        height: barHeight,
        valueLabel: compactMoney(amount),
        tooltip: `${formatDate(point.bucket)} · ${formatMoney(amount)} · ${Number(point.count) || 0} vente(s)`,
      };
    });

    const ticks = [1, 0.5, 0].map((ratio) => ({
      y: padding.top + plotHeight - ratio * plotHeight,
      label: compactMoney(max * ratio),
    }));

    return {
      width,
      height,
      left: padding.left,
      right: width - padding.right,
      top: padding.top,
      bottom: height - padding.bottom,
      plotBottom: padding.top + plotHeight,
      labelY: height - padding.bottom + 17,
      points,
      ticks,
    };
  });

  constructor() {
    effect((onCleanup) => {
      const term = this.searchTerm().trim();

      if (term === this.appliedSearch()) {
        return;
      }

      const timer = setTimeout(() => {
        this.appliedSearch.set(term);
        this.load(1);
      }, SEARCH_DELAY);

      onCleanup(() => clearTimeout(timer));
    });

    effect((onCleanup) => {
      const element = this.chartContainer()?.nativeElement;

      if (!element || typeof ResizeObserver === 'undefined') {
        return;
      }

      const observer = new ResizeObserver(([entry]) => {
        const width = Math.floor(entry?.contentRect.width ?? 0);

        if (width > 0) {
          this.chartWidth.set(width);
        }
      });

      observer.observe(element);
      onCleanup(() => observer.disconnect());
    });

    this.load();
  }

  protected load(pageNumber = 1): void {
    if (this.customRangeInvalid()) {
      this.reportError.set(null);
      return;
    }

    const filters = this.filters(pageNumber);
    const version = ++this.requestVersion;

    this.loading.set(true);
    this.reportError.set(null);
    this.summaryError.set(null);

    forkJoin({
      summary: this.api.summary(filters).pipe(
        map((value): LoadResult<ReportSummary> => ({ ok: true, value })),
        catchError((error: unknown) =>
          of<LoadResult<ReportSummary>>({
            ok: false,
            error: extractErrorMessage(error, 'Impossible de charger la synthèse.'),
          }),
        ),
      ),
      report: this.api.report(this.selectedType(), filters).pipe(
        map((value): LoadResult<ReportPage> => ({ ok: true, value })),
        catchError((error: unknown) =>
          of<LoadResult<ReportPage>>({
            ok: false,
            error: extractErrorMessage(error, 'Impossible de charger le rapport.'),
          }),
        ),
      ),
    })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          if (version === this.requestVersion) {
            this.loading.set(false);
          }
        }),
      )
      .subscribe(({ summary, report }) => {
        if (version !== this.requestVersion) {
          return;
        }

        if (summary.ok) {
          this.summary.set(summary.value);
        } else {
          this.summary.set(null);
          this.summaryError.set(summary.error);
        }

        if (report.ok) {
          this.page.set(report.value);
        } else {
          this.page.set(null);
          this.reportError.set(report.error);
        }
      });
  }

  protected onPeriodChange(period: ReportPeriod): void {
    this.period.set(period);
    this.reportError.set(null);

    if (period !== 'custom') {
      this.load(1);
    }
  }

  protected onTypeChange(type: ReportType): void {
    if (this.customRangeInvalid()) {
      return;
    }

    this.selectedType.set(type);
    this.load(1);
  }

  protected onSearchChange(value: string): void {
    this.searchTerm.set(value);
  }

  protected applyFilters(): void {
    if (this.customRangeInvalid()) {
      this.toast.error(
        this.customRangeError() ?? 'Choisissez une période valide avant d’appliquer les filtres.',
      );
      return;
    }

    this.appliedSearch.set(this.searchTerm().trim());
    this.load(1);
  }

  protected clearSearch(): void {
    this.searchTerm.set('');
    this.appliedSearch.set('');
    this.load(1);
  }

  protected resetFilters(): void {
    this.period.set('month');
    this.from.set('');
    this.to.set('');
    this.searchTerm.set('');
    this.appliedSearch.set('');
    this.load(1);
  }

  protected changePage(pageNumber: number): void {
    if (
      this.loading() ||
      this.customRangeInvalid() ||
      pageNumber < 1 ||
      pageNumber > (this.meta()?.last_page ?? 1)
    ) {
      return;
    }

    this.load(pageNumber);
  }

  protected cellValue(row: ReportRow, columnIndex: number): string {
    const column = this.columns()[columnIndex];

    return column ? formatCell(row, column) : '—';
  }

  protected isStatusColumn(columnIndex: number): boolean {
    const key = this.columns()[columnIndex]?.key;

    return key === 'payment_status' || key === 'status' || key === 'type' || key === 'is_active';
  }

  protected statusBadgeClass(row: ReportRow, columnIndex: number): string {
    const column = this.columns()[columnIndex];

    if (!column) {
      return STATUS_BADGE_CLASSES.neutral;
    }

    const value = String(fieldValue(row, column.key) ?? '');

    if (column.key === 'is_active') {
      return value === 'false' ? STATUS_BADGE_CLASSES.neutral : STATUS_BADGE_CLASSES.success;
    }

    if (column.key === 'type') {
      return value === 'in'
        ? STATUS_BADGE_CLASSES.success
        : value === 'out'
          ? STATUS_BADGE_CLASSES.danger
          : STATUS_BADGE_CLASSES.warning;
    }

    if (column.key === 'status') {
      if (value === 'open' || value === 'received') {
        return STATUS_BADGE_CLASSES.success;
      }

      if (value === 'pending') {
        return STATUS_BADGE_CLASSES.warning;
      }

      if (value === 'cancelled') {
        return STATUS_BADGE_CLASSES.danger;
      }

      return STATUS_BADGE_CLASSES.neutral;
    }

    if (value === 'paid') {
      return STATUS_BADGE_CLASSES.success;
    }

    if (value === 'partial') {
      return STATUS_BADGE_CLASSES.warning;
    }

    if (value === 'credit') {
      return STATUS_BADGE_CLASSES.danger;
    }

    return STATUS_BADGE_CLASSES.neutral;
  }

  protected money(value: unknown): string {
    return formatMoney(value);
  }

  protected count(value: unknown): string {
    return new Intl.NumberFormat('fr-FR').format(Number(value) || 0);
  }

  protected exportPdf(): void {
    if (this.exporting()) {
      return;
    }

    if (this.customRangeInvalid()) {
      const message = this.customRangeError() ?? 'Choisissez une période valide avant d’exporter.';
      this.reportError.set(message);
      this.toast.error(message);
      return;
    }

    this.exporting.set(true);

    this.api
      .pdf(this.selectedType(), this.filters())
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.exporting.set(false)),
      )
      .subscribe({
        next: (blob) => {
          downloadBlob(blob, this.pdfFileName());
          this.toast.success('Le rapport PDF a été téléchargé.');
        },
        error: (error: unknown) => {
          void this.showPdfError(error);
        },
      });
  }

  protected print(): void {
    if (!this.summary() && !this.hasResults()) {
      return;
    }

    if (this.customRangeInvalid()) {
      const message = this.customRangeError() ?? 'Choisissez une période valide avant d’imprimer.';
      this.toast.error(message);
      return;
    }

    window.print();
  }

  private filters(page = 1): ReportFilters {
    return {
      period: this.period(),
      ...(this.period() === 'custom' ? { from: this.from(), to: this.to() } : {}),
      search: this.appliedSearch(),
      page,
      per_page: PER_PAGE,
    };
  }

  private pdfFileName(): string {
    const period = this.summary()?.period;
    const suffix = period ? `${period.from}_${period.to}` : this.period();

    return `rapport-${this.selectedType()}-${suffix}.pdf`;
  }

  private async showPdfError(error: unknown): Promise<void> {
    this.toast.error(await this.pdfErrorMessage(error));
  }

  private async pdfErrorMessage(error: unknown): Promise<string> {
    if (error instanceof HttpErrorResponse && error.error instanceof Blob) {
      try {
        const body = JSON.parse(await error.error.text()) as {
          message?: string;
          errors?: Record<string, string[]>;
        };
        const firstFieldError = body.errors ? Object.values(body.errors)[0]?.[0] : undefined;

        if (firstFieldError) {
          return firstFieldError;
        }

        if (body.message) {
          return body.message;
        }
      } catch {
        // Le corps n'est pas du JSON : on utilise le message de repli.
      }
    }

    return extractErrorMessage(error, 'Export PDF impossible.');
  }
}

function compactMoney(value: number): string {
  if (value >= 1_000_000) {
    return `${(value / 1_000_000).toFixed(1).replace('.', ',')} M`;
  }

  if (value >= 1_000) {
    return `${Math.round(value / 1_000)} k`;
  }

  return `${Math.round(value)}`;
}

function bucketLabel(bucket: string): string {
  const day = /^(\d{4})-(\d{2})-(\d{2})$/.exec(bucket);

  if (day) {
    const date = new Date(Number(day[1]), Number(day[2]) - 1, Number(day[3]));

    return new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit' }).format(date);
  }

  const month = /^(\d{4})-(\d{2})$/.exec(bucket);

  if (month) {
    return `${month[2]}/${month[1]}`;
  }

  return bucket;
}
