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
import { FormsModule } from '@angular/forms';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { extractErrorMessage } from '../../core/http/api-error';
import { downloadBlob } from '../../core/http/download-file';
import { ToastService } from '../../core/toast/toast-service';
import { SaleService } from '../../services/sale-service';
import {
  ManualPayment,
  ManualPaymentMethod,
  ManualPaymentsResponse,
  ManualPaymentStatusFilter,
  PaymentMethod,
  PaymentStatus,
  PaymentVerificationStatus,
  Sale,
  SalesPage,
} from '../../services/sale.model';

/**
 * Nombre de ventes affichees par page.
 *
 * Le backend plafonne `per_page` a 100 : quinze ventes par page laisse une
 * liste lisible tout en gardant la pagination utile.
 */
const PER_PAGE = 15;

/**
 * Nombre de paiements manuels affiches par page.
 *
 * L'API accepte la pagination optionnelle : sans `page` ni `per_page`, elle
 * conserve son ancien contrat et renvoie toute la file.
 */
const MANUAL_PER_PAGE = 10;

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

/** Libelle lisible de l'etat de verification d'un paiement manuel. */
const VERIFICATION_LABELS: Record<PaymentVerificationStatus, string> = {
  pending: 'En attente',
  confirmed: 'Confirmé',
  rejected: 'Rejeté',
};

/** Couleurs du badge de verification. */
const VERIFICATION_CLASSES: Record<PaymentVerificationStatus, string> = {
  pending: 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/25',
  confirmed: 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/25',
  rejected: 'bg-red-500/15 text-red-300 ring-1 ring-red-500/25',
};

/** Modes dont la reference de transaction est obligatoire. */
const MANUAL_METHODS: readonly PaymentMethod[] = ['wave', 'orange_money'];

/**
 * Historique des ventes.
 *
 * La liste est chargee depuis `GET /api/sales`, avec les filtres de recherche
 * et de statut appliques par le backend. Chaque ligne ouvre une modale de
 * detail : articles vendus et modes de paiement de la vente.
 */
@Component({
  selector: 'app-sale-list',
  imports: [DatePipe, DecimalPipe, FormsModule],
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

  /** Vente dont un reglement est en cours de saisie. */
  protected readonly paymentSale = signal<Sale | null>(null);

  /** Vrai pendant l'enregistrement d'un reglement. */
  protected readonly submittingPayment = signal(false);

  /** Message d'erreur du formulaire de reglement. */
  protected readonly paymentError = signal<string | null>(null);

  /** Cle d'idempotence du reglement en cours. */
  private readonly paymentIdempotencyKey = signal<string | null>(null);

  /** Statut filtre dans la file de verification manuelle. */
  protected readonly manualStatusFilter = signal<ManualPaymentStatusFilter>('pending');

  /** Moyen de paiement filtre dans la file de verification manuelle. */
  protected readonly manualMethodFilter = signal<ManualPaymentMethod | ''>('');

  /** Recherche saisie sur la reference de transaction. */
  protected readonly manualSearchTerm = signal('');

  /** Recherche stabilisee, envoyee a l'API. */
  private readonly manualAppliedSearch = signal('');

  /** Page courante de la file de verification manuelle. */
  protected readonly manualCurrentPage = signal(1);

  /** Paiements manuels charges selon les filtres de la file. */
  protected readonly manualPayments = httpResource<ManualPaymentsResponse>(() =>
    this.saleService.manualPaymentsRequest({
      status: this.manualStatusFilter(),
      method: this.manualMethodFilter() || undefined,
      search: this.manualAppliedSearch() || undefined,
      per_page: MANUAL_PER_PAGE,
      page: this.manualCurrentPage(),
    }),
  );

  /**
   * Total global des paiements en attente.
   *
   * Une requete dediee, independante des filtres courants, demande une page de
   * un element : seul `meta.total` est utilise. Aucun total n'est affiche si
   * l'API ne fournit pas cette metadonnee.
   */
  protected readonly manualPendingSummary = httpResource<ManualPaymentsResponse>(() =>
    this.saleService.manualPaymentsRequest({
      status: 'pending',
      per_page: 1,
      page: 1,
    }),
  );

  /** Paiements manuels affiches dans la file de verification. */
  protected readonly manualPaymentRows = computed(() => this.manualPayments.value()?.data ?? []);

  /** Pagination renvoyee pour la file filtree. */
  protected readonly manualMeta = computed(() => this.manualPayments.value()?.meta);

  /** Total global des paiements en attente, si l'API le fournit. */
  protected readonly manualPendingTotal = computed(
    () => this.manualPendingSummary.value()?.meta?.total ?? null,
  );

  /** Total des paiements correspondant aux filtres courants, si fourni. */
  protected readonly manualFilteredTotal = computed(() => this.manualMeta()?.total ?? null);

  /** Nombre de paiements charges sur la page courante. */
  protected readonly manualDisplayedCount = computed(() => this.manualPaymentRows().length);

  /** Nombre de pages de la file filtree. */
  protected readonly manualTotalPages = computed(() =>
    Math.max(1, this.manualMeta()?.last_page ?? 1),
  );

  /** Vrai quand une page precedente de la file existe. */
  protected readonly manualHasPreviousPage = computed(
    () => (this.manualMeta()?.current_page ?? 1) > 1,
  );

  /** Vrai quand une page suivante de la file existe. */
  protected readonly manualHasNextPage = computed(
    () => (this.manualMeta()?.current_page ?? 1) < this.manualTotalPages(),
  );

  /** Vrai quand la file ne renvoie aucun paiement. */
  protected readonly manualPaymentsEmpty = computed(
    () =>
      !this.manualPayments.isLoading() &&
      !this.manualPayments.error() &&
      this.manualPaymentRows().length === 0,
  );

  /** Vrai quand un filtre de la file manuelle est actif. */
  protected readonly manualHasFilter = computed(
    () =>
      this.manualSearchTerm().trim() !== '' ||
      this.manualMethodFilter() !== '' ||
      this.manualStatusFilter() !== 'pending',
  );

  /** Message d'erreur de la file de verification. */
  protected readonly manualPaymentsError = computed<string | null>(() => {
    const error = this.manualPayments.error();

    return error
      ? extractErrorMessage(error, 'Impossible de charger les paiements à vérifier.')
      : null;
  });

  /** Paiement manuel ouvert dans la modale de consultation. */
  protected readonly selectedManualPayment = signal<ManualPayment | null>(null);

  /** Paiement manuel ouvert dans la modale de verification. */
  protected readonly reviewPayment = signal<ManualPayment | null>(null);

  /** Action choisie pour le paiement ouvert. */
  protected readonly reviewMode = signal<'confirm' | 'reject'>('confirm');

  /** Motif de rejet saisi. */
  protected readonly reviewReason = signal('');

  /** Vrai pendant la confirmation ou le rejet. */
  protected readonly reviewSubmitting = signal(false);

  /** Message d'erreur de la modale de verification. */
  protected readonly reviewError = signal<string | null>(null);

  /** Champs du formulaire de reglement. */
  protected paymentForm: {
    amount: number;
    method: PaymentMethod;
    reference: string;
  } = {
    amount: 0,
    method: 'cash',
    reference: '',
  };

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
        if (term === this.appliedSearch() && this.currentPage() === 1) {
          return;
        }

        this.appliedSearch.set(term);
        this.currentPage.set(1);
      }, SEARCH_DELAY);

      onCleanup(() => clearTimeout(timer));
    });

    // La recherche de la file manuelle est stabilisee de la meme facon : le
    // backend filtre sur la reference de transaction.
    effect((onCleanup) => {
      const term = this.manualSearchTerm().trim();

      const timer = setTimeout(() => {
        if (term !== this.manualAppliedSearch()) {
          this.manualAppliedSearch.set(term);
          this.manualCurrentPage.set(1);
        }
      }, SEARCH_DELAY);

      onCleanup(() => clearTimeout(timer));
    });

    // Apres une decision, la derniere page peut devenir vide. On revient
    // alors sur la derniere page valide renvoyee par l'API.
    effect(() => {
      const meta = this.manualMeta();

      if (!meta || this.manualPayments.isLoading()) {
        return;
      }

      const lastPage = Math.max(1, meta.last_page);

      if (this.manualCurrentPage() > lastPage) {
        this.manualCurrentPage.set(lastPage);
      }
    });
  }

  /** Met a jour le terme de recherche a chaque frappe. */
  protected onSearch(event: Event): void {
    this.searchTerm.set((event.target as HTMLInputElement).value);
  }

  /** Reinitialise tous les filtres de l'historique. */
  protected clearSearch(): void {
    this.searchTerm.set('');
    this.statusFilter.set('');
    this.appliedSearch.set('');
    this.currentPage.set(1);
  }

  /** Filtre l'historique sur le statut de reglement. */
  protected onStatusFilter(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    this.statusFilter.set(value === '' ? '' : (value as PaymentStatus));
    this.currentPage.set(1);
  }

  /** Met a jour la recherche de la file manuelle. */
  protected onManualSearch(event: Event): void {
    this.manualSearchTerm.set((event.target as HTMLInputElement).value);
  }

  /** Filtre la file manuelle par statut de verification. */
  protected onManualStatusFilter(event: Event): void {
    this.manualStatusFilter.set(
      (event.target as HTMLSelectElement).value as ManualPaymentStatusFilter,
    );
    this.manualCurrentPage.set(1);
  }

  /** Filtre la file manuelle par moyen de paiement. */
  protected onManualMethodFilter(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    this.manualMethodFilter.set(value === '' ? '' : (value as ManualPaymentMethod));
    this.manualCurrentPage.set(1);
  }

  /** Reinitialise les filtres de la file manuelle. */
  protected clearManualFilters(): void {
    this.manualSearchTerm.set('');
    this.manualAppliedSearch.set('');
    this.manualMethodFilter.set('');
    this.manualStatusFilter.set('pending');
    this.manualCurrentPage.set(1);
  }

  /** Affiche la page demandee de l'historique. */
  protected goToPage(page: number): void {
    if (this.sales.isLoading() || page < 1 || page > this.totalPages()) {
      return;
    }

    this.currentPage.set(page);
  }

  /** Affiche la page suivante de l'historique. */
  protected nextPage(): void {
    if (!this.sales.isLoading() && this.hasNextPage()) {
      this.currentPage.update((page) => page + 1);
    }
  }

  /** Affiche la page precedente de l'historique. */
  protected previousPage(): void {
    if (!this.sales.isLoading() && this.hasPreviousPage()) {
      this.currentPage.update((page) => page - 1);
    }
  }

  /** Relance le chargement de l'historique. */
  protected reload(): void {
    if (this.sales.isLoading()) {
      return;
    }

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

  /** Recharge la file des paiements a verifier et son total global. */
  protected reloadManualPayments(): void {
    if (!this.manualPayments.isLoading()) {
      this.manualPayments.reload();
    }

    if (!this.manualPendingSummary.isLoading()) {
      this.manualPendingSummary.reload();
    }
  }

  /** Affiche la page precedente de la file manuelle. */
  protected previousManualPage(): void {
    if (!this.manualPayments.isLoading() && this.manualHasPreviousPage()) {
      this.manualCurrentPage.update((page) => page - 1);
    }
  }

  /** Affiche la page suivante de la file manuelle. */
  protected nextManualPage(): void {
    if (!this.manualPayments.isLoading() && this.manualHasNextPage()) {
      this.manualCurrentPage.update((page) => page + 1);
    }
  }

  /** Ouvre la modale de reglement d'une vente a credit. */
  protected openPayment(sale: Sale): void {
    this.paymentForm = {
      amount: Number(sale.available_due),
      method: 'cash',
      reference: '',
    };
    this.paymentError.set(null);
    this.paymentIdempotencyKey.set(null);
    this.paymentSale.set(sale);
  }

  /** Ferme la modale de reglement. */
  protected closePayment(): void {
    if (this.submittingPayment()) {
      return;
    }

    this.paymentSale.set(null);
    this.paymentError.set(null);
    this.paymentIdempotencyKey.set(null);
  }

  /**
   * Reinitialise la cle d'idempotence quand le formulaire change.
   *
   * Un reessai strictement identique reutilise la cle ; une modification du
   * montant, du mode ou de la reference produit une nouvelle tentative.
   */
  protected onPaymentFormChange(): void {
    this.paymentIdempotencyKey.set(null);
  }

  /** Enregistre le reglement saisi sur la vente selectionnee. */
  protected submitPayment(): void {
    const sale = this.paymentSale();

    if (sale === null || this.submittingPayment()) {
      return;
    }

    const amount = Number(this.paymentForm.amount);
    const available = Number(sale.available_due);
    const method = this.paymentForm.method;
    const reference = this.paymentForm.reference.trim();

    if (!Number.isFinite(amount) || amount <= 0) {
      this.paymentError.set('Le montant doit être supérieur à zéro.');

      return;
    }

    if (amount > available) {
      this.paymentError.set(
        Number(sale.pending_amount) > 0
          ? 'Le montant dépasse le solde disponible après paiements en attente.'
          : 'Le montant dépasse le reste à payer.',
      );

      return;
    }

    if (this.requiresReference(method) && reference === '') {
      this.paymentError.set(
        'La référence de transaction est obligatoire pour Wave et Orange Money.',
      );

      return;
    }

    this.submittingPayment.set(true);
    this.paymentError.set(null);

    this.saleService
      .addPayment(sale.id, {
        amount,
        method,
        reference: reference || undefined,
        idempotency_key: this.idempotencyKeyForPaymentAttempt(),
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (updated) => {
          this.submittingPayment.set(false);
          this.paymentSale.set(null);
          this.paymentIdempotencyKey.set(null);

          if (this.selectedSale()?.id === updated.id) {
            this.selectedSale.set(updated);
          }

          const pending = this.requiresReference(method);

          this.toastService.success(
            pending
              ? `Règlement de ${amount.toLocaleString('fr-FR')} FCFA en attente de vérification.`
              : `Règlement de ${amount.toLocaleString('fr-FR')} FCFA enregistré.`,
          );

          if (pending) {
            this.reloadManualPayments();
          }

          this.reload();
        },
        error: (error: unknown) => {
          this.submittingPayment.set(false);
          this.paymentError.set(
            extractErrorMessage(error, 'Impossible d’enregistrer le règlement.'),
          );
        },
      });
  }

  /** Ouvre la modale de consultation detaillee d'un paiement manuel. */
  protected openManualDetails(payment: ManualPayment): void {
    this.selectedManualPayment.set(payment);
  }

  /** Ferme la modale de consultation d'un paiement manuel. */
  protected closeManualDetails(): void {
    this.selectedManualPayment.set(null);
  }

  /** Ouvre la modale de confirmation ou de rejet d'un paiement manuel. */
  protected openReview(payment: ManualPayment, mode: 'confirm' | 'reject'): void {
    this.selectedManualPayment.set(null);
    this.reviewPayment.set(payment);
    this.reviewMode.set(mode);
    this.reviewReason.set('');
    this.reviewError.set(null);
  }

  /** Ferme la modale de verification. */
  protected closeReview(): void {
    if (this.reviewSubmitting()) {
      return;
    }

    this.reviewPayment.set(null);
    this.reviewReason.set('');
    this.reviewError.set(null);
  }

  /** Confirme ou rejette le paiement manuel ouvert. */
  protected submitReview(): void {
    const payment = this.reviewPayment();

    if (payment === null || this.reviewSubmitting()) {
      return;
    }

    const mode = this.reviewMode();
    const reason = this.reviewReason().trim();

    if (mode === 'reject' && reason === '') {
      this.reviewError.set('Le motif du rejet est obligatoire.');

      return;
    }

    this.reviewSubmitting.set(true);
    this.reviewError.set(null);

    const request =
      mode === 'confirm'
        ? this.saleService.confirmManualPayment(payment.id)
        : this.saleService.rejectManualPayment(payment.id, reason);

    request.pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (updated) => {
        this.reviewSubmitting.set(false);
        this.reviewPayment.set(null);
        this.reviewReason.set('');

        if (this.selectedSale()?.id === updated.id) {
          this.selectedSale.set(updated);
        }

        this.toastService.success(
          mode === 'confirm'
            ? `Paiement ${payment.reference ?? ''} confirmé.`
            : `Paiement ${payment.reference ?? ''} rejeté.`,
        );

        this.reloadManualPayments();
        this.reload();
      },
      error: (error: unknown) => {
        this.reviewSubmitting.set(false);
        this.reviewError.set(extractErrorMessage(error, 'Impossible de traiter ce paiement.'));
      },
    });
  }

  /** Cree la cle d'idempotence de la tentative de reglement courante. */
  private idempotencyKeyForPaymentAttempt(): string {
    const existing = this.paymentIdempotencyKey();

    if (existing !== null) {
      return existing;
    }

    const key =
      typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

    this.paymentIdempotencyKey.set(key);

    return key;
  }

  /** Ferme la modale quand la touche Echap est pressee. */
  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    if (this.reviewPayment()) {
      this.closeReview();

      return;
    }

    if (this.paymentSale()) {
      this.closePayment();

      return;
    }

    if (this.selectedManualPayment()) {
      this.closeManualDetails();

      return;
    }

    if (this.selectedSale()) {
      this.closeDetails();
    }
  }

  /**
   * Indique si la reference est obligatoire pour ce mode de paiement.
   */
  protected requiresReference(method: PaymentMethod): boolean {
    return MANUAL_METHODS.includes(method);
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
   * Libelle lisible de l'etat de verification d'un paiement manuel.
   */
  protected verificationLabel(status: PaymentVerificationStatus): string {
    return VERIFICATION_LABELS[status] ?? status;
  }

  /**
   * Couleurs du badge de verification.
   */
  protected verificationClasses(status: PaymentVerificationStatus): string {
    return VERIFICATION_CLASSES[status] ?? VERIFICATION_CLASSES['pending'];
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
