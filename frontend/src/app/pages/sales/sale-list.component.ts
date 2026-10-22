import { DatePipe, DecimalPipe } from '@angular/common';
import { httpResource } from '@angular/common/http';
import {
  Component,
  DestroyRef,
  HostListener,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { extractErrorMessage } from '../../core/http/api-error';
import { downloadBlob } from '../../core/http/download-file';
import { ToastService } from '../../core/toast/toast-service';
import { SaleService } from '../../services/sale-service';
import { PaymentMethod, PaymentStatus, Sale, SalesPage } from '../../services/sale.model';

/**
 * Nombre de ventes affichees par page.
 *
 * Le backend plafonne `per_page` a 100 : quinze ventes par page laisse une
 * liste lisible tout en gardant la pagination utile.
 */
const PER_PAGE = 15;

/**
 * Delai de stabilisation de la recherche, en millisecondes.
 *
 * Une seule requete part apres une pause dans la frappe, au lieu d'une
 * requete par caractere saisi.
 */
const SEARCH_DELAY = 300;

/** Libelle lisible de chaque mode de paiement. */
const PAYMENT_LABELS: Record<PaymentMethod, string> = {
  cash: 'Espèces',
  wave: 'Wave',
  orange_money: 'Orange Money',
  bank_transfer: 'Virement bancaire',
  check: 'Chèque',
  credit: 'Crédit',
};

/** Libelle lisible de chaque statut de reglement. */
const STATUS_LABELS: Record<PaymentStatus, string> = {
  paid: 'Payé',
  partial: 'Partiel',
  credit: 'Crédit',
};

/** Couleurs du badge de statut, selon le reglement de la vente. */
const STATUS_CLASSES: Record<PaymentStatus, string> = {
  paid: 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/25',
  partial: 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/25',
  credit: 'bg-red-500/15 text-red-300 ring-1 ring-red-500/25',
};

/**
 * Historique des ventes.
 *
 * La liste est chargee depuis `GET /api/sales`, avec les filtres de recherche
 * et de statut appliques par le backend. Chaque ligne ouvre une modale de
 * detail : articles vendus et modes de paiement de la vente.
 */
@Component({
  selector: 'app-sale-list',
  imports: [DatePipe, DecimalPipe],
  templateUrl: './sale-list.component.html',
  styleUrl: './sale-list.component.css',
})
export class SaleListComponent {
  private readonly saleService = inject(SaleService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly toastService = inject(ToastService);

  /** Terme de recherche saisi dans la barre de recherche. */
  protected readonly searchTerm = signal('');

  /** Terme de recherche stabilise, envoye a l'API. */
  private readonly appliedSearch = signal('');

  /** Statut de reglement filtre, ou une chaine vide pour tous. */
  protected readonly statusFilter = signal<PaymentStatus | ''>('');

  /** Page courante de l'historique. */
  protected readonly currentPage = signal(1);

  /** Vente dont le detail est affiche dans la modale. */
  protected readonly selectedSale = signal<Sale | null>(null);

  /** Vente dont la facture est en cours de generation. */
  protected readonly downloadingInvoiceId = signal<number | null>(null);

  /** Historique des ventes, filtre et pagine par le backend. */
  protected readonly sales = httpResource<SalesPage>(() =>
    this.saleService.listRequest({
      search: this.appliedSearch() || undefined,
      payment_status: this.statusFilter() || undefined,
      per_page: PER_PAGE,
      page: this.currentPage(),
    }),
  );

  /** Ventes affichees sur la page courante. */
  protected readonly rows = computed<Sale[]>(() => this.sales.value()?.data ?? []);

  /** Pagination renvoyee par l'API. */
  protected readonly meta = computed(() => this.sales.value()?.meta);

  /** Nombre total de ventes correspondant aux filtres. */
  protected readonly totalCount = computed(() => this.meta()?.total ?? 0);

  /** Nombre de pages de l'historique. */
  protected readonly totalPages = computed(() => this.meta()?.last_page ?? 1);

  /** Vrai quand une page precedente existe. */
  protected readonly hasPreviousPage = computed(() => (this.meta()?.current_page ?? 1) > 1);

  /** Vrai quand une page suivante existe. */
  protected readonly hasNextPage = computed(
    () => (this.meta()?.current_page ?? 1) < this.totalPages(),
  );

  /** Message d'erreur lisible quand l'historique ne peut pas etre charge. */
  protected readonly errorMessage = computed<string | null>(() => {
    const error = this.sales.error();

    return error
      ? extractErrorMessage(error, 'Impossible de charger l’historique des ventes.')
      : null;
  });

  /** Vrai quand les filtres ne renvoient aucune vente. */
  protected readonly isEmpty = computed(
    () => !this.sales.isLoading() && !this.sales.error() && this.rows().length === 0,
  );

  /** Vrai quand un filtre est actif (recherche ou statut). */
  protected readonly hasFilter = computed(
    () => this.searchTerm().trim() !== '' || this.statusFilter() !== '',
  );

  constructor() {
    // La recherche est stabilisee : une seule requete part apres une pause
    // dans la frappe. Chaque recherche repart de la premiere page.
    effect((onCleanup) => {
      const term = this.searchTerm().trim();

      const timer = setTimeout(() => {
        this.appliedSearch.set(term);
        this.currentPage.set(1);
      }, SEARCH_DELAY);

      onCleanup(() => clearTimeout(timer));
    });
  }

  /** Met a jour le terme de recherche a chaque frappe. */
  protected onSearch(event: Event): void {
    this.searchTerm.set((event.target as HTMLInputElement).value);
  }

  /** Reinitialise le filtre de recherche. */
  protected clearSearch(): void {
    this.searchTerm.set('');
  }

  /** Filtre l'historique sur le statut de reglement. */
  protected onStatusFilter(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    this.statusFilter.set(value === '' ? '' : (value as PaymentStatus));
    this.currentPage.set(1);
  }

  /** Affiche la page demandee de l'historique. */
  protected goToPage(page: number): void {
    if (page < 1 || page > this.totalPages()) {
      return;
    }

    this.currentPage.set(page);
  }

  /** Affiche la page suivante de l'historique. */
  protected nextPage(): void {
    if (this.hasNextPage()) {
      this.currentPage.update((page) => page + 1);
    }
  }

  /** Affiche la page precedente de l'historique. */
  protected previousPage(): void {
    if (this.hasPreviousPage()) {
      this.currentPage.update((page) => page - 1);
    }
  }

  /** Relance le chargement de l'historique. */
  protected reload(): void {
    this.sales.reload();
  }

  /** Ouvre la modale de detail d'une vente. */
  protected openDetails(sale: Sale): void {
    this.selectedSale.set(sale);
  }

  /** Ferme la modale de detail. */
  protected closeDetails(): void {
    this.selectedSale.set(null);
  }

  /**
   * Telecharge la facture PDF d'une vente.
   *
   * Le PDF arrive sous forme de blob : un lien temporaire declenche le
   * telechargement, sans quitter la page.
   *
   * @param sale Vente dont la facture est demandee.
   */
  protected downloadInvoice(sale: Sale): void {
    if (this.downloadingInvoiceId() !== null) {
      return;
    }

    this.downloadingInvoiceId.set(sale.id);

    this.saleService
      .downloadInvoice(sale.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (blob) => {
          this.downloadingInvoiceId.set(null);
          downloadBlob(blob, `facture-${sale.reference}.pdf`);
        },
        error: (error: unknown) => {
          this.downloadingInvoiceId.set(null);
          this.toastService.error(
            extractErrorMessage(error, 'Impossible de telecharger la facture.'),
          );
        },
      });
  }

  /** Ferme la modale quand la touche Echap est pressee. */
  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    if (this.selectedSale()) {
      this.closeDetails();
    }
  }

  /**
   * Libelle lisible d'un mode de paiement.
   *
   * @param method Mode de paiement enregistre.
   */
  protected paymentLabel(method: PaymentMethod): string {
    return PAYMENT_LABELS[method] ?? method;
  }

  /**
   * Libelle lisible d'un statut de reglement.
   *
   * @param status Statut de reglement de la vente.
   */
  protected statusLabel(status: PaymentStatus): string {
    return STATUS_LABELS[status] ?? status;
  }

  /**
   * Couleurs du badge d'un statut de reglement.
   *
   * @param status Statut de reglement de la vente.
   */
  protected statusClasses(status: PaymentStatus): string {
    return STATUS_CLASSES[status] ?? STATUS_CLASSES['credit'];
  }
}
