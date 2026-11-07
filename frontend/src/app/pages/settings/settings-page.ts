import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { AbstractControl, FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import { AuthService } from '../../core/auth/auth-service';
import { extractErrorMessage } from '../../core/http/api-error';
import { ToastService } from '../../core/toast/toast-service';
import { SettingsService } from '../../services/settings-service';

@Component({
  selector: 'app-settings-page',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './settings-page.html',
  styleUrl: './settings-page.css',
})
export class SettingsPage {
  private readonly api = inject(SettingsService);
  private readonly formBuilder = inject(FormBuilder);
  private readonly destroyRef = inject(DestroyRef);
  private readonly toast = inject(ToastService);
  protected readonly auth = inject(AuthService);
  protected readonly isAdmin = computed(() => this.auth.hasRole('Admin'));
  protected readonly canManageUsers = computed(
    () => this.auth.hasPermission('gerer_utilisateurs') || this.auth.hasRole('Admin', 'Gérant'),
  );
  protected readonly loading = signal(true);
  protected readonly companyLoading = signal(false);
  protected readonly savingCompany = signal(false);
  protected readonly uploadingLogo = signal(false);
  protected readonly savingProfile = signal(false);
  protected readonly savingPassword = signal(false);
  protected readonly companyError = signal<string | null>(null);
  protected readonly profileError = signal<string | null>(null);
  protected readonly passwordError = signal<string | null>(null);
  protected readonly logoName = signal<string | null>(null);
  protected readonly logoPreviewUrl = signal<string | null>(null);
  protected readonly logoStored = signal(false);

  protected readonly companyForm = this.formBuilder.nonNullable.group({
    business_name: ['', [Validators.required, Validators.maxLength(160)]],
    phone: ['', Validators.maxLength(40)],
    email: ['', [Validators.email, Validators.maxLength(160)]],
    address: ['', Validators.maxLength(1000)],
    invoice_footer: ['', Validators.maxLength(500)],
  });
  protected readonly profileForm = this.formBuilder.nonNullable.group({
    name: ['', [Validators.required, Validators.maxLength(160)]],
    email: ['', [Validators.required, Validators.email, Validators.maxLength(160)]],
    phone: ['', Validators.maxLength(40)],
  });
  protected readonly passwordForm = this.formBuilder.nonNullable.group({
    current_password: ['', Validators.required],
    password: ['', [Validators.required, Validators.minLength(8)]],
    password_confirmation: ['', Validators.required],
  });

  constructor() {
    this.destroyRef.onDestroy(() => this.clearLogoPreview());

    if (this.isAdmin()) {
      this.companyLoading.set(true);
      this.api
        .getCompany()
        .pipe(
          takeUntilDestroyed(this.destroyRef),
          finalize(() => this.companyLoading.set(false)),
        )
        .subscribe({
          next: ({ settings }) => {
            this.companyForm.patchValue(settings);
            this.logoStored.set(Boolean(settings.logo_path));
          },
          error: (error: unknown) => {
            this.companyError.set(
              extractErrorMessage(error, 'Impossible de charger les paramètres entreprise.'),
            );
          },
        });
    }

    this.api
      .getProfile()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: ({ user }) => {
          this.profileForm.patchValue({
            name: user.name,
            email: user.email,
            phone: user.phone ?? '',
          });
          this.loading.set(false);
        },
        error: (error: unknown) => {
          this.profileError.set(extractErrorMessage(error, 'Impossible de charger le profil.'));
          this.loading.set(false);
        },
      });
  }

  protected fieldError(control: AbstractControl | null): string | null {
    if (!control || !(control.touched || control.dirty) || !control.errors) {
      return null;
    }

    if (control.errors['required']) {
      return 'Ce champ est obligatoire.';
    }

    if (control.errors['email']) {
      return 'Adresse e-mail invalide.';
    }

    if (control.errors['minlength']) {
      return `Minimum ${control.errors['minlength'].requiredLength} caractères.`;
    }

    if (control.errors['maxlength']) {
      return `Maximum ${control.errors['maxlength'].requiredLength} caractères.`;
    }

    return 'Valeur invalide.';
  }

  protected saveCompany(): void {
    if (this.savingCompany()) {
      return;
    }

    this.companyError.set(null);
    this.companyForm.markAllAsTouched();

    if (this.companyForm.invalid) {
      return;
    }

    this.savingCompany.set(true);
    this.api
      .saveCompany(this.companyForm.getRawValue())
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.savingCompany.set(false)),
      )
      .subscribe({
        next: ({ message }) => this.toast.success(message),
        error: (error: unknown) => {
          this.companyError.set(extractErrorMessage(error, 'Enregistrement impossible.'));
        },
      });
  }

  protected chooseLogo(event: Event): void {
    if (this.uploadingLogo()) {
      return;
    }

    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
      return;
    }

    if (
      !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) ||
      file.size > 2 * 1024 * 1024
    ) {
      this.companyError.set('Choisissez une image PNG, JPEG ou WebP de 2 Mo maximum.');
      input.value = '';
      return;
    }

    this.clearLogoPreview();
    this.logoName.set(file.name);
    this.companyError.set(null);

    if (typeof URL.createObjectURL === 'function') {
      this.logoPreviewUrl.set(URL.createObjectURL(file));
    }

    this.uploadingLogo.set(true);
    this.api
      .uploadLogo(file)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.uploadingLogo.set(false);
          input.value = '';
        }),
      )
      .subscribe({
        next: ({ settings, message }) => {
          this.companyForm.patchValue(settings);
          this.logoStored.set(Boolean(settings.logo_path));
          this.toast.success(message);
        },
        error: (error: unknown) => {
          this.clearLogoPreview();
          this.logoName.set(null);
          this.companyError.set(extractErrorMessage(error, 'Téléversement du logo impossible.'));
        },
      });
  }

  private clearLogoPreview(): void {
    const preview = this.logoPreviewUrl();

    if (preview && typeof URL.revokeObjectURL === 'function') {
      URL.revokeObjectURL(preview);
    }

    this.logoPreviewUrl.set(null);
  }

  protected saveProfile(): void {
    if (this.savingProfile()) {
      return;
    }

    this.profileError.set(null);
    this.profileForm.markAllAsTouched();
    if (this.profileForm.invalid) {
      return;
    }
    this.savingProfile.set(true);
    this.api
      .updateProfile(this.profileForm.getRawValue())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: ({ message }) => {
          this.toast.success(message);
          this.savingProfile.set(false);
          void this.auth.refreshProfile().catch(() => undefined);
        },
        error: (error: unknown) => {
          this.profileError.set(extractErrorMessage(error, 'Mise à jour impossible.'));
          this.savingProfile.set(false);
        },
      });
  }

  protected changePassword(): void {
    if (this.savingPassword()) {
      return;
    }

    this.passwordError.set(null);
    this.passwordForm.markAllAsTouched();
    const values = this.passwordForm.getRawValue();
    if (this.passwordForm.invalid) {
      return;
    }
    if (values.password !== values.password_confirmation) {
      this.passwordError.set('La confirmation ne correspond pas.');
      return;
    }
    this.savingPassword.set(true);
    this.api
      .changePassword(values)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: ({ message }) => {
          this.toast.success(message);
          this.passwordForm.reset();
          this.savingPassword.set(false);
        },
        error: (error: unknown) => {
          this.passwordError.set(
            extractErrorMessage(error, 'Modification du mot de passe impossible.'),
          );
          this.savingPassword.set(false);
        },
      });
  }
}
