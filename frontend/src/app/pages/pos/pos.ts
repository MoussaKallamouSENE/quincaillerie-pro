import { DatePipe, DecimalPipe } from '@angular/common';
import { httpResource } from '@angular/common/http';
import {
  Component,
  DestroyRef,
  ElementRef,
  computed,
  effect,
  inject,
  linkedSignal,
  signal,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { AbstractControl, FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { extractErrorMessage } from '../../core/http/api-error';
import { ToastService } from '../../core/toast/toast-service';
import { CashService } from '../../services/cash-service';
import { CashSession } from '../../services/cash.model';
import { CustomerService } from '../../services/customer-service';
import { Customer, CustomersPage } from '../../services/customer.model';
import { ExpenseService } from '../../services/expense-service';
import { Expense, ExpensesPage } from '../../services/expense.model';
import { ProductService } from '../../services/product-service';
import { Product, ProductsPage } from '../../services/product.model';
import { SaleService } from '../../services/sale-service';
import { PaymentMethod, SalePayload } from '../../services/sale.model';

/**
 * Nombre maximum de produits demandes a l'API.
 *
 * `per_page` est plafonne a 100 par le backend : le catalogue tient donc dans
 * une seule page, ce qui permet une recherche instantanee cote client.
 */
const MAX_PER_PAGE = 100;

/** Ligne du panier : un produit et la quantite vendue. */
interface CartLine {
  /** Produit vendu. */
  readonly product: Product;
  /** Quantite vendue. */
  readonly quantity: number;
  /** Prix unitaire applique a la caisse, en FCFA. */
  readonly unitPrice: number;
}

/** Mode de paiement propose a la caisse. */
interface PaymentOption {
  /** Methode transmise a l'API. */
  readonly method: PaymentMethod;
  /** Libelle affiche. */
  readonly label: string;
}

/** Modes de paiement proposes, dans l'ordre d'affichage. */
const PAYMENT_OPTIONS: readonly PaymentOption[] = [
  { method: 'cash', label: 'Espèces' },
  { method: 'wave', label: 'Wave' },
  { method: 'orange_money', label: 'Orange Money' },
  { method: 'credit', label: 'Crédit' },
];

/** Minuscule sans accents, pour comparer des libelles ignores la casse. */
function normalize(value: string): string {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '');
}

/** Formate un montant en FCFA, comme le `DecimalPipe` de l'interface. */
function formatMoney(amount: number): string {
  return new Intl.NumberFormat('fr-FR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount);
}

/**
 * Point de vente de la quincaillerie.
 *
 * L'ecran est organise autour d'une session de caisse : tant qu'aucune
 * session n'est ouverte, une modale bloquante demande le fond de caisse
 * initial. Une fois la caisse ouverte, l'ecran se partage entre le catalogue
 * (recherche par nom ou SKU) et le panier (lignes, client, reglement).
 */
@Component({
  selector: 'app-pos',
  imports: [DatePipe, DecimalPipe, ReactiveFormsModule],
  templateUrl: './pos.html',
  styleUrl: './pos.css',
})
export class Pos {
  private readonly cashService = inject(CashService);
  private readonly saleService = inject(SaleService);
  private readonly productService = inject(ProductService);
  private readonly customerService = inject(CustomerService);
  private readonly expenseService = inject(ExpenseService);
  private readonly toastService = inject(ToastService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly destroyRef = inject(DestroyRef);

  /** Modes de paiement proposes. */
  protected readonly paymentOptions = PAYMENT_OPTIONS;

  /** Session de caisse ouverte par le vendeur, `null` si aucune. */
  protected readonly cashSession = signal<CashSession | null>(null);

  /** Vrai pendant la verification de la session de caisse. */
  protected readonly sessionChecking = signal(true);

  /** Message d'erreur quand la session de caisse ne peut pas etre verifiee. */
  protected readonly sessionError = signal<string | null>(null);

  /** Resume de la cloture affiche apres la fermeture de la caisse. */
  protected readonly closingSummary = signal<CashSession | null>(null);

  /**
   * Catalogue des produits.
   *
   * La requete n'est declenchee qu'une fois la caisse ouverte : inutile de
   * charger le catalogue tant que la vente est impossible.
   */
  protected readonly products = httpResource<ProductsPage>(() =>
    this.cashSession() ? this.productService.listRequest({ per_page: MAX_PER_PAGE }) : undefined,
  );

  /** Clients selectionnables pour une vente partielle ou a credit. */
  protected readonly customers = httpResource<CustomersPage>(() =>
    this.cashSession() ? this.customerService.listRequest({ per_page: MAX_PER_PAGE }) : undefined,
  );

  /**
   * Depenses de la session de caisse ouverte.
   *
   * L'encart n'a de sens qu'une fois la caisse ouverte : la requete suit donc
   * la session, comme le catalogue et les clients.
   */
  protected readonly expenses = httpResource<ExpensesPage>(() =>
    this.cashSession() ? this.expenseService.listRequest() : undefined,
  );

  /** Depenses affichees dans l'encart, de la plus recente a la plus ancienne. */
  protected readonly expenseRows = computed<Expense[]>(() => this.expenses.value()?.data ?? []);

  /** Total des depenses de la session, en FCFA. */
  protected readonly expensesTotal = computed(() => {
    const meta = this.expenses.value()?.meta;

    return meta ? Number(meta.total) : Number(this.cashSession()?.expenses_total ?? 0);
  });

  /** Vrai pendant le chargement des depenses. */
  protected readonly expensesLoading = computed(() => this.expenses.isLoading());

  /** Message d'erreur lisible quand les depenses ne peuvent pas etre chargees. */
  protected readonly expensesError = computed<string | null>(() => {
    const error = this.expenses.error();

    return error ? extractErrorMessage(error, 'Impossible de charger les dépenses.') : null;
  });

  /** Terme de recherche saisi dans la barre de recherche. */
  protected readonly searchTerm = signal('');

  /** Produits filtres par nom ou SKU, selon le terme de recherche. */
  protected readonly filteredProducts = computed<Product[]>(() => {
    const products = this.products.value()?.data ?? [];
    const term = normalize(this.searchTerm().trim());

    if (!term) {
      return products;
    }

    return products.filter(
      (product) => normalize(product.name).includes(term) || normalize(product.sku).includes(term),
    );
  });

  /** Message d'erreur lisible quand le catalogue ne peut pas etre charge. */
  protected readonly productsError = computed<string | null>(() => {
    const error = this.products.error();

    return error ? extractErrorMessage(error, 'Impossible de charger le catalogue.') : null;
  });

  /** Vrai quand le catalogue est en cours de chargement. */
  protected readonly productsLoading = computed(() => this.products.isLoading());

  /** Vrai quand une recherche est saisie mais ne renvoie aucun produit. */
  protected readonly isEmptySearch = computed(
    () => this.searchTerm().trim().length > 0 && this.filteredProducts().length === 0,
  );

  /** Lignes du panier. */
  protected readonly cart = signal<CartLine[]>([]);

  /** Total du panier, en FCFA. */
  protected readonly cartTotal = computed(() =>
    this.cart().reduce((total, line) => total + line.unitPrice * line.quantity, 0),
  );

  /** Nombre d'articles presents dans le panier. */
  protected readonly cartCount = computed(() =>
    this.cart().reduce((count, line) => count + line.quantity, 0),
  );

  /** Mode de paiement selectionne. */
  protected readonly paymentMethod = signal<PaymentMethod>('cash');

  /**
   * Montant recu par le client.
   *
   * La valeur suit le total du panier tant que le vendeur ne l'edit pas : la
   * monnaie a rendre est donc toujours coherente avec la vente en cours.
   */
  protected readonly receivedAmount = linkedSignal(() => this.cartTotal());

  /** Client selectionne pour la vente. */
  protected readonly selectedCustomerId = signal<number | null>(null);

  /** Client selectionne, ou `null` pour une vente sans client. */
  protected readonly selectedCustomer = computed<Customer | null>(() => {
    const id = this.selectedCustomerId();

    if (id === null) {
      return null;
    }

    return this.customers.value()?.data.find((customer) => customer.id === id) ?? null;
  });

  /** Montant regle au moment de la vente (0 pour une vente a credit). */
  protected readonly paidAmount = computed(() => {
    if (this.paymentMethod() === 'credit') {
      return 0;
    }

    return Math.min(this.receivedAmount(), this.cartTotal());
  });

  /** Monnaie a rendre au client. */
  protected readonly changeDue = computed(() =>
    Math.max(0, this.receivedAmount() - this.cartTotal()),
  );

  /** Reliquat de la vente, reporte sur la dette du client. */
  protected readonly dueAmount = computed(() => Math.max(0, this.cartTotal() - this.paidAmount()));

  /** Vrai quand la modale bloquante d'ouverture doit etre affichee. */
  protected readonly needsOpening = computed(
    () =>
      !this.sessionChecking() &&
      this.sessionError() === null &&
      this.cashSession() === null &&
      this.closingSummary() === null,
  );

  /** Vrai pendant l'enregistrement de la vente. */
  protected readonly submitting = signal(false);

  /** Message d'erreur global de la vente (reponse du serveur). */
  protected readonly saleError = signal<string | null>(null);

  /** Vrai pendant l'ouverture de la caisse. */
  protected readonly openingSubmitting = signal(false);

  /** Message d'erreur de la modale d'ouverture. */
  protected readonly openingError = signal<string | null>(null);

  /** Formulaire d'ouverture de la caisse (fond de caisse initial). */
  protected readonly openingForm = this.formBuilder.group({
    opening_balance: [0, [Validators.required, Validators.min(0)]],
  });

  /** Vrai quand la modale de cloture est ouverte. */
  protected readonly closingModalOpen = signal(false);

  /** Vrai pendant la cloture de la caisse. */
  protected readonly closingSubmitting = signal(false);

  /** Message d'erreur de la modale de cloture. */
  protected readonly closingError = signal<string | null>(null);

  /** Formulaire de cloture (montant compte dans le tiroir). */
  protected readonly closingForm = this.formBuilder.group({
    closing_balance: [0, [Validators.required, Validators.min(0)]],
  });

  /** Vrai quand la modale d'enregistrement d'une depense est ouverte. */
  protected readonly expenseModalOpen = signal(false);

  /** Vrai pendant l'enregistrement de la depense. */
  protected readonly expenseSubmitting = signal(false);

  /** Message d'erreur de la modale de depense. */
  protected readonly expenseError = signal<string | null>(null);

  /** Formulaire de depense (motif et montant paye depuis le tiroir). */
  protected readonly expenseForm = this.formBuilder.group({
    name: ['', [Validators.required, Validators.maxLength(255)]],
    amount: [0, [Validators.required, Validators.min(0.01)]],
  });

  /** Champ du fond de caisse, cible du focus a l'ouverture de la modale. */
  private readonly openingInput = viewChild<ElementRef<HTMLInputElement>>('openingInput');

  /** Champ du motif, cible du focus a l'ouverture de la modale de depense. */
  private readonly expenseInput = viewChild<ElementRef<HTMLInputElement>>('expenseInput');

  constructor() {
    // Le focus revient sur le fond de caisse a chaque ouverture de la modale.
    effect(() => {
      if (this.needsOpening()) {
        this.openingInput()?.nativeElement.focus();
      }
    });

    // Le focus revient sur le motif a chaque ouverture de la modale de depense.
    effect(() => {
      if (this.expenseModalOpen()) {
        this.expenseInput()?.nativeElement.focus();
      }
    });

    // La session de caisse est verifiee a l'ouverture de l'ecran.
    this.loadSession();
  }

  /** Verifie la session de caisse du vendeur. */
  private loadSession(): void {
    this.sessionChecking.set(true);
    this.sessionError.set(null);

    this.cashService
      .getCurrentSession()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (session) => {
          this.cashSession.set(session);
          this.sessionChecking.set(false);
        },
        error: (error: unknown) => {
          this.sessionChecking.set(false);
          this.sessionError.set(
            extractErrorMessage(error, 'Impossible de vérifier la session de caisse.'),
          );
        },
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

  /**
   * Ajoute un produit au panier.
   *
   * La quantite ne peut pas depasser le stock disponible : la vente serait
   * refusee par le backend.
   */
  protected addToCart(product: Product): void {
    if (product.stock_quantity <= 0) {
      this.toastService.error(`« ${product.name} » est en rupture de stock.`);

      return;
    }

    const existing = this.cart().find((line) => line.product.id === product.id);

    if (existing) {
      this.increment(existing);

      return;
    }

    this.cart.update((lines) => [
      ...lines,
      { product, quantity: 1, unitPrice: Number(product.sale_price) },
    ]);
  }

  /** Augmente la quantite d'une ligne du panier. */
  protected increment(line: CartLine): void {
    if (line.quantity >= line.product.stock_quantity) {
      this.toastService.error(
        `Stock insuffisant pour « ${line.product.name} » (${line.product.stock_quantity} en stock).`,
      );

      return;
    }

    this.cart.update((lines) =>
      lines.map((item) =>
        item.product.id === line.product.id ? { ...item, quantity: item.quantity + 1 } : item,
      ),
    );
  }

  /** Diminue la quantite d'une ligne du panier. */
  protected decrement(line: CartLine): void {
    this.cart.update((lines) =>
      lines.flatMap((item) => {
        if (item.product.id !== line.product.id) {
          return [item];
        }

        return item.quantity > 1 ? [{ ...item, quantity: item.quantity - 1 }] : [];
      }),
    );
  }

  /** Retire une ligne du panier. */
  protected removeFromCart(line: CartLine): void {
    this.cart.update((lines) => lines.filter((item) => item.product.id !== line.product.id));
  }

  /** Vide le panier. */
  protected clearCart(): void {
    this.cart.set([]);
    this.saleError.set(null);
  }

  /** Selectionne le mode de paiement. */
  protected onSelectPaymentMethod(method: PaymentMethod): void {
    this.paymentMethod.set(method);
    this.receivedAmount.set(method === 'credit' ? 0 : this.cartTotal());
  }

  /** Selectionne le client de la vente. */
  protected onSelectCustomer(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    this.selectedCustomerId.set(value ? Number(value) : null);
  }

  /** Met a jour le montant recu. */
  protected onReceivedAmount(event: Event): void {
    const value = Number((event.target as HTMLInputElement).value);

    this.receivedAmount.set(Number.isFinite(value) ? value : 0);
  }

  /**
   * Enregistre la vente.
   *
   * Les regles controlees ici sont celles du backend : une vente a credit ou
   * partielle exige un client, et le montant regle ne peut pas depasser le
   * total.
   */
  protected onValidateSale(): void {
    const lines = this.cart();

    if (lines.length === 0) {
      this.saleError.set('Ajoutez au moins un produit au panier.');

      return;
    }

    const customerId = this.selectedCustomerId();

    if (this.dueAmount() > 0 && customerId === null) {
      this.saleError.set('Sélectionnez un client pour une vente partielle ou à crédit.');

      return;
    }

    const payload: SalePayload = {
      customer_id: customerId,
      items: lines.map((line) => ({ product_id: line.product.id, quantity: line.quantity })),
    };

    const paid = this.paidAmount();

    // La vente entierement a credit n'a pas de paiement : c'est le backend
    // qui reporte le total sur la dette du client.
    if (this.paymentMethod() !== 'credit' && paid > 0) {
      payload.payment = { method: this.paymentMethod(), amount: paid };
    }

    this.submitting.set(true);
    this.saleError.set(null);

    this.saleService
      .createSale(payload)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (sale) => {
          const change = this.changeDue();

          this.submitting.set(false);
          this.cart.set([]);
          this.selectedCustomerId.set(null);
          this.onSelectPaymentMethod('cash');
          this.saleError.set(null);

          this.toastService.success(
            change > 0
              ? `Vente ${sale.reference} enregistrée — monnaie à rendre : ${formatMoney(change)}.`
              : `Vente ${sale.reference} enregistrée avec succès.`,
          );

          // Le stock a change et le theorique de caisse aussi.
          this.products.reload();
          this.loadSession();
        },
        error: (error: unknown) => {
          this.submitting.set(false);
          this.saleError.set(extractErrorMessage(error, 'Impossible d’enregistrer la vente.'));
        },
      });
  }

  /** Ouvre la caisse avec le fond de caisse saisi. */
  protected onOpenSession(): void {
    if (this.openingForm.invalid) {
      this.openingForm.markAllAsTouched();

      return;
    }

    const openingBalance = Number(this.openingForm.getRawValue().opening_balance ?? 0);

    this.openingSubmitting.set(true);
    this.openingError.set(null);

    this.cashService
      .openSession(openingBalance)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (session) => {
          this.openingSubmitting.set(false);
          this.cashSession.set(session);
          this.toastService.success('Caisse ouverte. Vous pouvez enregistrer des ventes.');
        },
        error: (error: unknown) => {
          this.openingSubmitting.set(false);
          this.openingError.set(extractErrorMessage(error, 'Impossible d’ouvrir la caisse.'));
        },
      });
  }

  /** Ouvre la modale de cloture, initialisee sur le theorique du tiroir. */
  protected onOpenClosing(): void {
    const session = this.cashSession();

    this.closingForm.reset({ closing_balance: session ? Number(session.expected_cash) : 0 });
    this.closingError.set(null);
    this.closingModalOpen.set(true);
  }

  /** Ferme la modale de cloture sans cloturer la caisse. */
  protected onCancelClosing(): void {
    if (!this.closingSubmitting()) {
      this.closingModalOpen.set(false);
    }
  }

  /** Cloture la caisse avec le montant compte. */
  protected onCloseSession(): void {
    if (this.closingForm.invalid) {
      this.closingForm.markAllAsTouched();

      return;
    }

    const closingBalance = Number(this.closingForm.getRawValue().closing_balance ?? 0);

    this.closingSubmitting.set(true);
    this.closingError.set(null);

    this.cashService
      .closeSession(closingBalance)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (session) => {
          this.closingSubmitting.set(false);
          this.closingModalOpen.set(false);
          this.cashSession.set(null);
          this.closingSummary.set(session);

          const difference = Number(session.difference ?? 0);
          const message = `Caisse clôturée avec succès. Écart : ${formatMoney(difference)} FCFA`;

          // Un ecart negatif signale un manque de cash : il est signale en
          // rouge, la session etant bien cloturee.
          if (difference < 0) {
            this.toastService.error(message);
          } else {
            this.toastService.success(message);
          }
        },
        error: (error: unknown) => {
          this.closingSubmitting.set(false);
          this.closingError.set(extractErrorMessage(error, 'Impossible de clôturer la caisse.'));
        },
      });
  }

  /** Ferme le resume de cloture : l'ecran redemande l'ouverture de la caisse. */
  protected onDismissClosingSummary(): void {
    this.closingSummary.set(null);
  }

  /** Ouvre la modale d'enregistrement d'une depense. */
  protected onOpenExpense(): void {
    this.expenseForm.reset({ name: '', amount: 0 });
    this.expenseError.set(null);
    this.expenseModalOpen.set(true);
  }

  /** Ferme la modale de depense sans enregistrer. */
  protected onCancelExpense(): void {
    if (!this.expenseSubmitting()) {
      this.expenseModalOpen.set(false);
    }
  }

  /**
   * Enregistre la depense sur la session de caisse ouverte.
   *
   * Le backend rattache la depense a la session courante : son montant est
   * retire du tiroir, donc le solde theorique de la session est recharge.
   */
  protected onSaveExpense(): void {
    if (this.expenseForm.invalid) {
      this.expenseForm.markAllAsTouched();

      return;
    }

    const { name, amount } = this.expenseForm.getRawValue();

    this.expenseSubmitting.set(true);
    this.expenseError.set(null);

    this.expenseService
      .createExpense({ name: (name ?? '').trim(), amount: Number(amount ?? 0) })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (expense) => {
          this.expenseSubmitting.set(false);
          this.expenseModalOpen.set(false);

          this.toastService.success(
            `Dépense « ${expense.name} » enregistrée : ${formatMoney(Number(expense.amount))} FCFA.`,
          );

          // Le tiroir a change : l'encart et le theorique de caisse sont
          // rafraichis. `reload()` est necessaire car l'URL ne change pas.
          this.expenses.reload();
          this.loadSession();
        },
        error: (error: unknown) => {
          this.expenseSubmitting.set(false);
          this.expenseError.set(extractErrorMessage(error, 'Impossible d’enregistrer la dépense.'));
        },
      });
  }

  /**
   * Indique si l'ecart de caisse est un excedent.
   *
   * Un ecart positif signifie que le tiroir contient plus d'especes que le
   * solde theorique (fond de caisse + ventes en especes - depenses) ; un ecart
   * negatif signale un manque.
   *
   * @param session Session cloturee.
   */
  protected isSurplus(session: CashSession): boolean {
    return Number(session.difference ?? 0) >= 0;
  }

  /** Relance la verification de la session de caisse. */
  protected onRetrySession(): void {
    this.loadSession();
  }

  /**
   * Message d'erreur d'un champ, s'il est invalide et a ete touche.
   *
   * @param control Controle du formulaire a inspecter.
   */
  protected errorFor(control: AbstractControl): string | null {
    const errors = control.errors;

    if (!control.touched || !errors) {
      return null;
    }

    if (errors['required']) {
      return 'Ce champ est obligatoire.';
    }

    if (errors['min']) {
      return 'La valeur doit être positive.';
    }

    return 'Valeur invalide.';
  }
}
