import { DecimalPipe } from '@angular/common';
import { httpResource } from '@angular/common/http';
import {
  Component,
  DestroyRef,
  ElementRef,
  HostListener,
  computed,
  effect,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { AbstractControl, FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { AuthService } from '../../core/auth/auth-service';
import { extractErrorMessage } from '../../core/http/api-error';
import { ToastService } from '../../core/toast/toast-service';
import { ProductService } from '../../services/product-service';
import { Product, ProductDraft, ProductsPage } from '../../services/product.model';
import { ReferenceService } from '../../services/reference-service';
import { CategoryList, UnitList } from '../../services/reference.model';

/**
 * Nombre maximum de produits demandes a l'API.
 *
 * `per_page` est plafonne a 100 par le backend : le catalogue entier tient
 * donc dans une seule page, ce qui permet un filtrage instantane cote client.
 */
const MAX_PER_PAGE = 100;

/** Minuscule sans accents, pour comparer des libelles ignores la casse. */
function normalize(value: string): string {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '');
}

/**
 * Catalogue des produits.
 *
 * Les produits sont charges via `ProductService` et filtres cote client sur
 * leur nom : la recherche est donc instantanee, sans aller-retour reseau.
 */
@Component({
  selector: 'app-product-list',
  imports: [DecimalPipe, ReactiveFormsModule],
  templateUrl: './product-list.html',
  styleUrl: './product-list.css',
})
export class ProductList {
  private readonly productService = inject(ProductService);
  private readonly referenceService = inject(ReferenceService);
  private readonly toastService = inject(ToastService);
  private readonly auth = inject(AuthService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly destroyRef = inject(DestroyRef);

  /** Terme de recherche saisi dans la barre de recherche. */
  protected readonly searchTerm = signal('');

  /**
   * Vrai si l'utilisateur peut consulter les prix d'achat.
   *
   * Les prix d'achat sont reserves aux administrateurs et aux gerants : un
   * employe ne doit pas connaitre le cout de la marchandise.
   */
  protected readonly canSeePurchasePrice = computed(() => this.auth.hasRole('Admin', 'Gérant'));

  /**
   * Vrai si l'utilisateur peut gerer le catalogue.
   *
   * La creation et la modification des produits sont reservees aux
   * responsables, car le formulaire contient le prix d'achat.
   */
  protected readonly canManage = computed(() => this.auth.hasRole('Admin', 'Gérant'));

  /** Catalogue complet des produits. */
  protected readonly products = httpResource<ProductsPage>(() =>
    this.productService.listRequest({ per_page: MAX_PER_PAGE }),
  );

  /** Produits filtres par nom, selon le terme de recherche. */
  protected readonly filteredProducts = computed<Product[]>(() => {
    const page = this.products.hasValue() ? this.products.value() : undefined;
    const products = page?.data ?? [];
    const term = normalize(this.searchTerm().trim());

    if (!term) {
      return products;
    }

    return products.filter((product) => normalize(product.name).includes(term));
  });

  /** Message d'erreur lisible quand le catalogue ne peut pas etre charge. */
  protected readonly errorMessage = computed<string | null>(() => {
    const error = this.products.error();

    return error ? extractErrorMessage(error, 'Impossible de charger le catalogue.') : null;
  });

  /** Nombre de produits affiches apres filtrage. */
  protected readonly resultCount = computed(() => this.filteredProducts().length);

  /** Nombre de produits du catalogue, filtre ou non. */
  protected readonly totalCount = computed(() => {
    const page = this.products.hasValue() ? this.products.value() : undefined;

    return page?.meta.total ?? 0;
  });

  /** Vrai quand une recherche est saisie mais ne renvoie aucun produit. */
  protected readonly isEmptySearch = computed(
    () => this.searchTerm().trim().length > 0 && this.filteredProducts().length === 0,
  );

  /** Vrai quand la modale de creation est ouverte. */
  protected readonly isModalOpen = signal(false);

  /** Vrai pendant l'enregistrement du produit. */
  protected readonly submitting = signal(false);

  /** Message d'erreur global du formulaire (reponse du serveur). */
  protected readonly formError = signal<string | null>(null);

  /**
   * Formulaire de creation d'un produit.
   *
   * Les listes de reference (categories, unites) sont chargees a l'ouverture
   * de la modale : elles ne servent qu'a lui.
   */
  protected readonly form = this.formBuilder.group({
    name: ['', [Validators.required, Validators.maxLength(255)]],
    sku: ['', [Validators.required, Validators.maxLength(255)]],
    category_id: [null as number | null, Validators.required],
    unit_id: [null as number | null, Validators.required],
    purchase_price: [null as number | null, [Validators.required, Validators.min(0)]],
    sale_price: [null as number | null, [Validators.required, Validators.min(0)]],
    stock_quantity: [0, [Validators.required, Validators.min(0)]],
    min_stock_alert: [0, [Validators.required, Validators.min(0)]],
  });

  /**
   * Categories disponibles dans le formulaire.
   *
   * La ressource ne declenche sa requete que lorsque la modale est ouverte :
   * `httpResource` est eager, on renvoie donc `undefined` hors modale.
   */
  protected readonly categories = httpResource<CategoryList>(() =>
    this.isModalOpen() ? this.referenceService.categoriesRequest() : undefined,
  );

  /** Unites de mesure disponibles dans le formulaire. */
  protected readonly units = httpResource<UnitList>(() =>
    this.isModalOpen() ? this.referenceService.unitsRequest() : undefined,
  );

  /** Options de la liste des categories. */
  protected readonly categoryOptions = computed(() => this.categories.value()?.data ?? []);

  /** Options de la liste des unites de mesure. */
  protected readonly unitOptions = computed(() => this.units.value()?.data ?? []);

  /** Vrai quand les listes de reference sont en cours de chargement. */
  protected readonly referenceLoading = computed(
    () => this.categories.isLoading() || this.units.isLoading(),
  );

  /** Message d'erreur quand les listes de reference ne peuvent pas etre chargees. */
  protected readonly referenceError = computed<string | null>(() => {
    const error = this.categories.error() ?? this.units.error();

    return error
      ? extractErrorMessage(error, 'Impossible de charger les listes de reference.')
      : null;
  });

  /** Champ nom, cible du focus a l'ouverture de la modale. */
  private readonly nameInput = viewChild<ElementRef<HTMLInputElement>>('nameInput');

  constructor() {
    // Le focus revient sur le premier champ a chaque ouverture de la modale.
    effect(() => {
      if (this.isModalOpen()) {
        this.nameInput()?.nativeElement.focus();
      }
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

  /** Relance le chargement du catalogue. */
  protected reload(): void {
    this.products.reload();
  }

  /** Ouvre la modale de creation et reinitialise le formulaire. */
  protected onCreateProduct(): void {
    if (!this.canManage()) {
      return;
    }

    this.form.reset({
      name: '',
      sku: '',
      category_id: null,
      unit_id: null,
      purchase_price: null,
      sale_price: null,
      stock_quantity: 0,
      min_stock_alert: 0,
    });
    this.formError.set(null);
    this.isModalOpen.set(true);
  }

  /** Ferme la modale de creation. */
  protected closeModal(): void {
    this.isModalOpen.set(false);
    this.submitting.set(false);
    this.formError.set(null);
  }

  /** Ferme la modale quand la touche Echap est pressee. */
  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    if (this.isModalOpen()) {
      this.closeModal();
    }
  }

  /**
   * Message d'erreur d'un champ, s'il est invalide et a ete touche.
   *
   * @param control Controle du formulaire a inspecter.
   */
  protected errorFor(control: AbstractControl): string | null {
    if (!control.touched || !control.errors) {
      return null;
    }

    if (control.errors['required']) {
      return 'Ce champ est obligatoire.';
    }

    if (control.errors['min']) {
      return 'La valeur doit être positive.';
    }

    if (control.errors['maxlength']) {
      return 'Ce champ est trop long.';
    }

    return 'Valeur invalide.';
  }

  /**
   * Enregistre le produit saisi dans le formulaire.
   *
   * Le formulaire est d'abord valide cote client ; en cas d'echec, le message
   * renvoye par l'API est affiche dans la modale.
   */
  protected onSubmit(): void {
    if (this.submitting()) {
      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.formError.set('Veuillez corriger les champs en surbrillance.');

      return;
    }

    const value = this.form.getRawValue();
    const draft: ProductDraft = {
      name: (value.name ?? '').trim(),
      sku: (value.sku ?? '').trim(),
      category_id: Number(value.category_id),
      unit_id: Number(value.unit_id),
      purchase_price: Number(value.purchase_price),
      sale_price: Number(value.sale_price),
      stock_quantity: Number(value.stock_quantity),
      min_stock_alert: Number(value.min_stock_alert),
    };

    this.submitting.set(true);
    this.formError.set(null);

    this.productService
      .createProduct(draft)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (product) => {
          this.closeModal();
          this.toastService.success(`Produit « ${product.name} » créé avec succès.`);
          this.products.reload();
        },
        error: (error: unknown) => {
          this.submitting.set(false);
          this.formError.set(extractErrorMessage(error, 'Impossible de créer le produit.'));
        },
      });
  }
}
