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
import { extractErrorMessage } from '../../core/http/api-error';
import { ToastService } from '../../core/toast/toast-service';
import { CustomerService } from '../../services/customer-service';
import { Customer, CustomerDraft, CustomersPage } from '../../services/customer.model';

/**
 * Nombre maximum de clients demandes a l'API.
 *
 * `per_page` est plafonne a 100 par le backend : le fichier clients tient donc
 * dans une seule page, ce qui permet un filtrage instantane cote client.
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
 * Fichier des clients.
 *
 * Les clients sont charges via `CustomerService` et filtres cote client sur
 * leur nom : la recherche est donc instantanee, sans aller-retour reseau.
 */
@Component({
  selector: 'app-customer-list',
  imports: [DecimalPipe, ReactiveFormsModule],
  templateUrl: './customer-list.html',
  styleUrl: './customer-list.css',
})
export class CustomerList {
  private readonly customerService = inject(CustomerService);
  private readonly toastService = inject(ToastService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly destroyRef = inject(DestroyRef);

  /** Terme de recherche saisi dans la barre de recherche. */
  protected readonly searchTerm = signal('');

  /** Fichier complet des clients. */
  protected readonly customers = httpResource<CustomersPage>(() =>
    this.customerService.listRequest({ per_page: MAX_PER_PAGE }),
  );

  /** Clients filtres par nom, selon le terme de recherche. */
  protected readonly filteredCustomers = computed<Customer[]>(() => {
    const page = this.customers.hasValue() ? this.customers.value() : undefined;
    const customers = page?.data ?? [];
    const term = normalize(this.searchTerm().trim());

    if (!term) {
      return customers;
    }

    return customers.filter((customer) => normalize(customer.name).includes(term));
  });

  /** Message d'erreur lisible quand le fichier clients ne peut pas etre charge. */
  protected readonly errorMessage = computed<string | null>(() => {
    const error = this.customers.error();

    return error ? extractErrorMessage(error, 'Impossible de charger les clients.') : null;
  });

  /** Nombre de clients affiches apres filtrage. */
  protected readonly resultCount = computed(() => this.filteredCustomers().length);

  /** Nombre de clients du fichier, filtre ou non. */
  protected readonly totalCount = computed(() => {
    const page = this.customers.hasValue() ? this.customers.value() : undefined;

    return page?.meta.total ?? 0;
  });

  /** Vrai quand une recherche est saisie mais ne renvoie aucun client. */
  protected readonly isEmptySearch = computed(
    () => this.searchTerm().trim().length > 0 && this.filteredCustomers().length === 0,
  );

  /** Vrai quand la modale de creation est ouverte. */
  protected readonly isModalOpen = signal(false);

  /** Vrai pendant l'enregistrement du client. */
  protected readonly submitting = signal(false);

  /** Message d'erreur global du formulaire (reponse du serveur). */
  protected readonly formError = signal<string | null>(null);

  /**
   * Formulaire de creation d'un client.
   *
   * Le solde du client n'y figure pas : il est calcule par le backend a
   * partir des ventes et des reglements.
   */
  protected readonly form = this.formBuilder.group({
    name: ['', [Validators.required, Validators.maxLength(255)]],
    phone: ['', [Validators.maxLength(255)]],
    email: ['', [Validators.email, Validators.maxLength(255)]],
    address: ['', [Validators.maxLength(1000)]],
    credit_limit: [0, [Validators.required, Validators.min(0)]],
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

  /** Relance le chargement du fichier clients. */
  protected reload(): void {
    this.customers.reload();
  }

  /** Ouvre la modale de creation et reinitialise le formulaire. */
  protected onCreateCustomer(): void {
    this.form.reset({
      name: '',
      phone: '',
      email: '',
      address: '',
      credit_limit: 0,
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
   * Vrai quand le client doit de l'argent a la quincaillerie.
   *
   * La dette est renvoyee en chaine par le backend (`decimal:2`) : elle est
   * convertie en nombre pour la comparaison.
   *
   * @param customer Client dont la dette est inspectee.
   */
  protected hasDebt(customer: Customer): boolean {
    return Number(customer.outstanding_balance) > 0;
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

    if (control.errors['min']) {
      return 'La valeur doit être positive.';
    }

    if (control.errors['maxlength']) {
      return 'Ce champ est trop long.';
    }

    return 'Valeur invalide.';
  }

  /**
   * Enregistre le client saisi dans le formulaire.
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
    const draft: CustomerDraft = {
      name: (value.name ?? '').trim(),
      phone: (value.phone ?? '').trim() || null,
      email: (value.email ?? '').trim() || null,
      address: (value.address ?? '').trim() || null,
      credit_limit: Number(value.credit_limit ?? 0),
    };

    this.submitting.set(true);
    this.formError.set(null);

    this.customerService
      .createCustomer(draft)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (customer) => {
          this.closeModal();
          this.toastService.success(`Client « ${customer.name} » créé avec succès.`);
          this.customers.reload();
        },
        error: (error: unknown) => {
          this.submitting.set(false);
          this.formError.set(extractErrorMessage(error, 'Impossible de créer le client.'));
        },
      });
  }
}
