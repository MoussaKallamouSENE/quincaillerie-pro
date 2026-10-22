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
import { AppUser, USER_ROLES, UserDraft, UsersPage } from '../../services/user.model';
import { UserService } from '../../services/user-service';

/**
 * Nombre maximum d'utilisateurs demandes a l'API.
 *
 * `per_page` est plafonne a 100 par le backend : l'equipe tient donc dans une
 * seule page, ce qui permet un filtrage instantane cote client.
 */
const MAX_PER_PAGE = 100;

/** Classes communes aux badges de role. */
const ROLE_BADGE_BASE =
  'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold tracking-wide ring-1 ring-inset';

/** Couleur du badge selon le role : Admin rouge, Gerant bleu, Employe vert. */
const ROLE_BADGE_CLASSES: Record<string, string> = {
  Admin: 'bg-red-500/15 text-red-300 ring-red-500/30',
  Gérant: 'bg-blue-500/15 text-blue-300 ring-blue-500/30',
  Employé: 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30',
};

/** Minuscules sans accents, pour comparer des libelles ignores la casse. */
function normalize(value: string): string {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '');
}

/**
 * Gestion des utilisateurs.
 *
 * La liste est chargee via `UserService` et filtree cote client : la recherche
 * est instantanee, sans aller-retour reseau. La creation et la modification
 * partagent la meme modale, la suppression passe par une confirmation.
 */
@Component({
  selector: 'app-user-list',
  imports: [ReactiveFormsModule],
  templateUrl: './user-list.html',
  styleUrl: './user-list.css',
})
export class UserList {
  private readonly userService = inject(UserService);
  private readonly auth = inject(AuthService);
  private readonly toastService = inject(ToastService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly destroyRef = inject(DestroyRef);

  /** Roles proposes dans le formulaire. */
  protected readonly roles = USER_ROLES;

  /** Terme de recherche saisi dans la barre de recherche. */
  protected readonly searchTerm = signal('');

  /** Equipe complete, chargee en une page. */
  protected readonly users = httpResource<UsersPage>(() =>
    this.userService.listRequest({ per_page: MAX_PER_PAGE }),
  );

  /** Utilisateurs filtres par nom, email, telephone ou role. */
  protected readonly filteredUsers = computed<AppUser[]>(() => {
    const page = this.users.hasValue() ? this.users.value() : undefined;
    const users = page?.data ?? [];
    const term = normalize(this.searchTerm().trim());

    if (!term) {
      return users;
    }

    return users.filter(
      (user) =>
        normalize(user.name).includes(term) ||
        normalize(user.email).includes(term) ||
        normalize(user.phone ?? '').includes(term) ||
        normalize(user.role ?? '').includes(term),
    );
  });

  /** Message d'erreur lisible quand l'equipe ne peut pas etre chargee. */
  protected readonly errorMessage = computed<string | null>(() => {
    const error = this.users.error();

    return error ? extractErrorMessage(error, 'Impossible de charger les utilisateurs.') : null;
  });

  /** Nombre d'utilisateurs affiches apres filtrage. */
  protected readonly resultCount = computed(() => this.filteredUsers().length);

  /** Nombre d'utilisateurs du fichier, filtre ou non. */
  protected readonly totalCount = computed(() => {
    const page = this.users.hasValue() ? this.users.value() : undefined;

    return page?.meta.total ?? 0;
  });

  /** Vrai quand une recherche est saisie mais ne renvoie aucun utilisateur. */
  protected readonly isEmptySearch = computed(
    () => this.searchTerm().trim().length > 0 && this.filteredUsers().length === 0,
  );

  /** Vrai quand la modale de creation ou de modification est ouverte. */
  protected readonly isModalOpen = signal(false);

  /** Utilisateur en cours de modification, `null` en creation. */
  protected readonly editingUser = signal<AppUser | null>(null);

  /** Vrai quand la modale edite un compte existant. */
  protected readonly isEditing = computed(() => this.editingUser() !== null);

  /** Vrai pendant l'enregistrement du compte. */
  protected readonly submitting = signal(false);

  /** Message d'erreur global du formulaire (reponse du serveur). */
  protected readonly formError = signal<string | null>(null);

  /** Utilisateur dont la suppression est en attente de confirmation. */
  protected readonly userToDelete = signal<AppUser | null>(null);

  /** Vrai pendant la suppression du compte. */
  protected readonly deleting = signal(false);

  /** Message d'erreur de la confirmation de suppression. */
  protected readonly deleteError = signal<string | null>(null);

  /**
   * Formulaire de creation/modification d'un utilisateur.
   *
   * Le mot de passe est obligatoire a la creation et facultatif en
   * modification (laisse vide pour conserver le mot de passe actuel).
   */
  protected readonly form = this.formBuilder.group({
    name: ['', [Validators.required, Validators.maxLength(255)]],
    email: ['', [Validators.required, Validators.email, Validators.maxLength(255)]],
    phone: ['', [Validators.maxLength(255)]],
    password: ['', [Validators.required, Validators.minLength(8)]],
    role: ['Employé', [Validators.required]],
    is_active: [true],
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

  /** Relance le chargement de l'equipe. */
  protected reload(): void {
    this.users.reload();
  }

  /** Vrai si le compte affiche est celui de l'utilisateur connecte. */
  protected isCurrentUser(user: AppUser): boolean {
    return this.auth.user()?.id === user.id;
  }

  /** Initiales affichees dans l'avatar du tableau. */
  protected initials(name: string): string {
    return name
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase())
      .join('');
  }

  /** Classes du badge de role, avec la couleur attendue par role. */
  protected roleBadgeClasses(role: string | null): string {
    const color =
      (role && ROLE_BADGE_CLASSES[role]) || 'bg-slate-500/15 text-slate-300 ring-slate-500/30';

    return `${ROLE_BADGE_BASE} ${color}`;
  }

  /** Ouvre la modale de creation et reinitialise le formulaire. */
  protected onCreateUser(): void {
    this.editingUser.set(null);
    this.form.reset({
      name: '',
      email: '',
      phone: '',
      password: '',
      role: 'Employé',
      is_active: true,
    });
    this.form.controls.password.setValidators([Validators.required, Validators.minLength(8)]);
    this.form.controls.password.updateValueAndValidity();
    this.formError.set(null);
    this.isModalOpen.set(true);
  }

  /** Ouvre la modale de modification sur le compte selectionne. */
  protected onEditUser(user: AppUser): void {
    this.editingUser.set(user);
    this.form.reset({
      name: user.name,
      email: user.email,
      phone: user.phone ?? '',
      password: '',
      role: user.role ?? 'Employé',
      is_active: user.is_active,
    });
    // En modification, un mot de passe vide conserve le mot de passe actuel.
    this.form.controls.password.setValidators([Validators.minLength(8)]);
    this.form.controls.password.updateValueAndValidity();
    this.formError.set(null);
    this.isModalOpen.set(true);
  }

  /** Ferme la modale de creation/modification. */
  protected closeModal(): void {
    this.isModalOpen.set(false);
    this.editingUser.set(null);
    this.submitting.set(false);
    this.formError.set(null);
  }

  /** Demande confirmation avant de supprimer un compte. */
  protected onDeleteUser(user: AppUser): void {
    if (this.isCurrentUser(user)) {
      return;
    }

    this.userToDelete.set(user);
    this.deleteError.set(null);
  }

  /** Ferme la confirmation de suppression. */
  protected cancelDelete(): void {
    this.userToDelete.set(null);
    this.deleting.set(false);
    this.deleteError.set(null);
  }

  /** Supprime le compte confirme. */
  protected onConfirmDelete(): void {
    const user = this.userToDelete();

    if (!user) {
      return;
    }

    this.deleting.set(true);
    this.deleteError.set(null);

    this.userService
      .deleteUser(user.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.cancelDelete();
          this.toastService.success(`Utilisateur « ${user.name} » supprimé.`);
          this.users.reload();
        },
        error: (error: unknown) => {
          this.deleting.set(false);
          this.deleteError.set(
            extractErrorMessage(error, 'Impossible de supprimer cet utilisateur.'),
          );
        },
      });
  }

  /** Ferme la modale ouverte quand la touche Echap est pressee. */
  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    if (this.userToDelete()) {
      this.cancelDelete();

      return;
    }

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

    if (control.errors['minlength']) {
      return 'Le mot de passe doit contenir au moins 8 caractères.';
    }

    if (control.errors['maxlength']) {
      return 'Ce champ est trop long.';
    }

    return 'Valeur invalide.';
  }

  /**
   * Enregistre le compte saisi dans le formulaire.
   *
   * Le formulaire est d'abord valide cote client ; en cas d'echec, le message
   * renvoye par l'API est affiche dans la modale.
   */
  protected onSubmit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.formError.set('Veuillez corriger les champs en surbrillance.');

      return;
    }

    const value = this.form.getRawValue();
    const draft: UserDraft = {
      name: (value.name ?? '').trim(),
      email: (value.email ?? '').trim(),
      phone: (value.phone ?? '').trim() || null,
      role: value.role ?? 'Employé',
      is_active: value.is_active ?? true,
    };

    const password = (value.password ?? '').trim();
    const editing = this.editingUser();

    this.submitting.set(true);
    this.formError.set(null);

    const request = editing
      ? this.userService.updateUser(editing.id, password ? { ...draft, password } : draft)
      : this.userService.createUser({ ...draft, password });

    request.pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (user) => {
        const wasEditing = editing !== null;

        this.closeModal();
        this.toastService.success(
          wasEditing
            ? `Utilisateur « ${user.name} » modifié.`
            : `Utilisateur « ${user.name} » créé avec succès.`,
        );
        this.users.reload();
      },
      error: (error: unknown) => {
        this.submitting.set(false);
        this.formError.set(
          extractErrorMessage(
            error,
            editing
              ? 'Impossible de modifier l’utilisateur.'
              : 'Impossible de créer l’utilisateur.',
          ),
        );
      },
    });
  }
}
