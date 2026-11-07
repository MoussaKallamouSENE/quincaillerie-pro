import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { extractErrorMessage } from '../../core/http/api-error';
import { ToastService } from '../../core/toast/toast-service';
import { ReferenceService } from '../../services/reference-service';
import { CatalogCategory, CategoryDraft } from '../../services/reference.model';

/** Minuscule sans accents, pour comparer des libelles ignores la casse. */
function normalize(value: string): string {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '');
}

/**
 * Gestion des catégories du catalogue.
 *
 * La lecture est ouverte à tous les utilisateurs authentifiés ; la création,
 * la modification et la suppression sont réservées aux Admin et Gérants par
 * le guard de route et le middleware Laravel.
 */
@Component({
  selector: 'app-category-list',
  imports: [FormsModule],
  templateUrl: './category-list.html',
})
export class CategoryListComponent {
  private readonly referenceService = inject(ReferenceService);
  private readonly toastService = inject(ToastService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly categories = signal<CatalogCategory[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly searchTerm = signal('');
  protected readonly showModal = signal(false);
  protected readonly submitting = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly editingId = signal<number | null>(null);
  protected readonly deletingId = signal<number | null>(null);

  /** Donnees du formulaire de creation/modification. */
  protected form: {
    name: string;
    description: string;
    parent_id: number | null;
    is_active: boolean;
  } = {
    name: '',
    description: '',
    parent_id: null,
    is_active: true,
  };

  /** Categories filtrees par nom ou description. */
  protected readonly filteredCategories = computed<CatalogCategory[]>(() => {
    const term = normalize(this.searchTerm().trim());

    if (!term) {
      return this.categories();
    }

    return this.categories().filter(
      (category) =>
        normalize(category.name).includes(term) ||
        normalize(category.description ?? '').includes(term),
    );
  });

  /** Categories proposees comme parente, en excluant la categorie editee. */
  protected readonly parentOptions = computed<CatalogCategory[]>(() =>
    this.categories().filter((category) => category.id !== this.editingId()),
  );

  protected readonly resultCount = computed(() => this.filteredCategories().length);

  /** Vrai quand la modale est ouverte en modification. */
  protected readonly isEditing = computed(() => this.editingId() !== null);

  constructor() {
    this.load();
  }

  /** Charge la liste des categories depuis l'API. */
  protected load(): void {
    this.loading.set(true);
    this.error.set(null);

    this.referenceService
      .getCategories()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.categories.set(response.data);
          this.loading.set(false);
        },
        error: (error: unknown) => {
          this.loading.set(false);
          this.error.set(extractErrorMessage(error, 'Impossible de charger les catégories.'));
        },
      });
  }

  /** Met a jour le terme de recherche. */
  protected onSearch(event: Event): void {
    this.searchTerm.set((event.target as HTMLInputElement).value);
  }

  /** Ouvre la modale de creation. */
  protected openCreate(): void {
    this.editingId.set(null);
    this.form = {
      name: '',
      description: '',
      parent_id: null,
      is_active: true,
    };
    this.formError.set(null);
    this.showModal.set(true);
  }

  /** Ouvre la modale de modification. */
  protected openEdit(category: CatalogCategory): void {
    this.editingId.set(category.id);
    this.form = {
      name: category.name,
      description: category.description ?? '',
      parent_id: category.parent_id,
      is_active: category.is_active,
    };
    this.formError.set(null);
    this.showModal.set(true);
  }

  /** Ferme la modale. */
  protected closeModal(): void {
    if (this.submitting()) {
      return;
    }

    this.showModal.set(false);
    this.formError.set(null);
  }

  /** Enregistre la categorie (creation ou modification). */
  protected save(): void {
    if (this.submitting()) {
      return;
    }

    const name = this.form.name.trim();

    if (!name) {
      this.formError.set('Le nom de la catégorie est obligatoire.');

      return;
    }

    const draft: CategoryDraft = {
      name,
      description: this.form.description.trim() || null,
      parent_id: this.form.parent_id,
      is_active: this.form.is_active,
    };

    const id = this.editingId();

    this.submitting.set(true);
    this.formError.set(null);

    const request$ =
      id === null
        ? this.referenceService.createCategory(draft)
        : this.referenceService.updateCategory(id, draft);

    request$.pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (response) => {
        this.submitting.set(false);
        this.showModal.set(false);
        this.toastService.success(
          id === null
            ? `Catégorie « ${response.data.name} » créée avec succès.`
            : `Catégorie « ${response.data.name} » mise à jour.`,
        );
        this.load();
      },
      error: (error: unknown) => {
        this.submitting.set(false);
        this.formError.set(extractErrorMessage(error, 'Impossible d’enregistrer la catégorie.'));
      },
    });
  }

  /** Supprime une categorie apres confirmation. */
  protected remove(category: CatalogCategory): void {
    if (this.deletingId() !== null) {
      return;
    }

    if (!confirm(`Supprimer la catégorie « ${category.name} » ? Cette action est définitive.`)) {
      return;
    }

    this.deletingId.set(category.id);
    this.referenceService
      .deleteCategory(category.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.deletingId.set(null);
          this.toastService.success(`Catégorie « ${category.name} » supprimée.`);
          this.load();
        },
        error: (error: unknown) => {
          this.deletingId.set(null);
          this.toastService.error(
            extractErrorMessage(error, 'Impossible de supprimer la catégorie.'),
          );
        },
      });
  }

  /** Nom de la categorie parente, ou un tiret. */
  protected parentName(category: CatalogCategory): string {
    return category.parent?.name ?? '—';
  }
}
