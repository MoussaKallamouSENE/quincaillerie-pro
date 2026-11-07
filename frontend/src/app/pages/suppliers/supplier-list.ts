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
import { extractErrorMessage } from '../../core/http/api-error';
import { ToastService } from '../../core/toast/toast-service';
import { SupplierService } from '../../services/supplier-service';
import { Supplier, SupplierDraft, SuppliersPage } from '../../services/supplier.model';

/**
 * Nombre maximum de fournisseurs demandes a l'API.
 *
 * `per_page` est plafonne a 100 par le backend : le fichier fournisseurs tient
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
 * Fichier des fournisseurs.
 *
 * Les fournisseurs sont charges via `SupplierService` et filtres cote client
 * sur leur nom : la recherche est donc instantanee, sans aller-retour reseau.
 */
@Component({
  selector: 'app-supplier-list',
  imports: [ReactiveFormsModule],
  templateUrl: './supplier-list.html',
  styleUrl: './supplier-list.css',
})
export class SupplierList {
  private readonly supplierService = inject(SupplierService);
  private readonly toastService = inject(ToastService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly destroyRef = inject(DestroyRef);

  /** Terme de recherche saisi dans la barre de recherche. */
  protected readonly searchTerm = signal('');

  /** Fichier complet des fournisseurs. */
  protected readonly suppliers = httpResource<SuppliersPage>(() =>
    this.supplierService.listRequest({ per_page: MAX_PER_PAGE }),
  );

  /** Fournisseurs filtres par nom, selon le terme de recherche. */
  protected readonly filteredSuppliers = computed<Supplier[]>(() => {
    const page = this.suppliers.hasValue() ? this.suppliers.value() : undefined;
    const suppliers = page?.data ?? [];
    const term = normalize(this.searchTerm().trim());

    if (!term) {
      return suppliers;
    }

    return suppliers.filter((supplier) => normalize(supplier.name).includes(term));
  });

  /** Message d'erreur lisible quand le fichier fournisseurs ne peut pas etre charge. */
  protected readonly errorMessage = computed<string | null>(() => {
    const error = this.suppliers.error();

    return error ? extractErrorMessage(error, 'Impossible de charger les fournisseurs.') : null;
  });

  /** Nombre de fournisseurs affiches apres filtrage. */
  protected readonly resultCount = computed(() => this.filteredSuppliers().length);

  /** Nombre de fournisseurs du fichier, filtre ou non. */
  protected readonly totalCount = computed(() => {
    const page = this.suppliers.hasValue() ? this.suppliers.value() : undefined;

    return page?.meta.total ?? 0;
  });

  /** Vrai quand une recherche est saisie mais ne renvoie aucun fournisseur. */
  protected readonly isEmptySearch = computed(
    () => this.searchTerm().trim().length > 0 && this.filteredSuppliers().length === 0,
  );

  /** Vrai quand la modale de creation est ouverte. */
  protected readonly isModalOpen = signal(false);

  /** Vrai pendant l'enregistrement du fournisseur. */
  protected readonly submitting = signal(false);

  /** Message d'erreur global du formulaire (reponse du serveur). */
  protected readonly formError = signal<string | null>(null);

  /** Formulaire de creation d'un fournisseur. */
  protected readonly form = this.formBuilder.group({
    name: ['', [Validators.required, Validators.maxLength(255)]],
    contact_person: ['', [Validators.maxLength(255)]],
    phone: ['', [Validators.maxLength(255)]],
    email: ['', [Validators.email, Validators.maxLength(255)]],
    address: ['', [Validators.maxLength(1000)]],
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

  /** Relance le chargement du fichier fournisseurs. */
  protected reload(): void {
    this.suppliers.reload();
  }

  /** Ouvre la modale de creation et reinitialise le formulaire. */
  protected onCreateSupplier(): void {
    this.form.reset({
      name: '',
      contact_person: '',
      phone: '',
      email: '',
      address: '',
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

    if (control.errors['email']) {
      return 'L’adresse e-mail n’est pas valide.';
    }

    if (control.errors['maxlength']) {
      return 'Ce champ est trop long.';
    }

    return 'Valeur invalide.';
  }

  /**
   * Enregistre le fournisseur saisi dans le formulaire.
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
    const draft: SupplierDraft = {
      name: (value.name ?? '').trim(),
      contact_person: (value.contact_person ?? '').trim() || null,
      phone: (value.phone ?? '').trim() || null,
      email: (value.email ?? '').trim() || null,
      address: (value.address ?? '').trim() || null,
    };

    this.submitting.set(true);
    this.formError.set(null);

    this.supplierService
      .createSupplier(draft)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (supplier) => {
          this.closeModal();
          this.toastService.success(`Fournisseur « ${supplier.name} » créé avec succès.`);
          this.suppliers.reload();
        },
        error: (error: unknown) => {
          this.submitting.set(false);
          this.formError.set(extractErrorMessage(error, 'Impossible de créer le fournisseur.'));
        },
      });
  }
}
