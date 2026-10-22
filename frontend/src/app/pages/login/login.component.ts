import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth-service';

/**
 * Fragment du message du serveur signalant un compte desactive.
 *
 * Le backend (`AuthController::login`) repond en HTTP 422 avec
 * `errors.email = ['Ce compte est desactive.']`.
 */
const DISABLED_ACCOUNT_MARKER = 'désactiv';

/** Forme du corps d'erreur renvoye par Laravel. */
interface LaravelErrorBody {
  message?: string;
  errors?: Record<string, string[]>;
}

@Component({
  selector: 'app-login',
  imports: [ReactiveFormsModule],
  templateUrl: './login.component.html',
  styleUrl: './login.component.css',
})
export class LoginComponent {
  private readonly formBuilder = inject(FormBuilder);
  private readonly authService = inject(AuthService);
  private readonly router = inject(Router);

  /** Message d'erreur affiche au-dessus du formulaire. */
  protected readonly errorMessage = signal<string | null>(null);

  /** Vrai pendant l'appel a `POST /api/login`. */
  protected readonly submitting = signal(false);

  /** Formulaire de connexion (Reactive Forms). */
  protected readonly form = this.formBuilder.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]],
  });

  /** Controle `email`, pour l'affichage des erreurs de validation. */
  protected get emailControl() {
    return this.form.controls.email;
  }

  /** Controle `password`, pour l'affichage des erreurs de validation. */
  protected get passwordControl() {
    return this.form.controls.password;
  }

  protected onSubmit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const { email, password } = this.form.getRawValue();

    this.submitting.set(true);
    this.errorMessage.set(null);

    this.authService.login(email, password).subscribe({
      next: (response) => {
        this.authService.saveToken(response.token);
        void this.router.navigate(['/dashboard']);
      },
      error: (error: unknown) => {
        this.errorMessage.set(this.resolveErrorMessage(error));
        this.submitting.set(false);
      },
    });
  }

  /**
   * Traduit l'erreur HTTP en message affiche a l'utilisateur.
   *
   * Laravel repond en HTTP 422 pour un echec de connexion, avec un message
   * different selon la cause :
   * - identifiants faux  -> « Les identifiants fournis sont incorrects. »
   * - compte desactive   -> « Ce compte est desactive. »
   */
  private resolveErrorMessage(error: unknown): string {
    if (!(error instanceof HttpErrorResponse)) {
      return 'Identifiants incorrects';
    }

    if (error.status === 0) {
      return 'Serveur injoignable. Vérifiez que le backend est démarré sur http://localhost:8000.';
    }

    const serverMessage = this.extractServerMessage(error);

    if (serverMessage?.toLowerCase().includes(DISABLED_ACCOUNT_MARKER)) {
      return 'Compte désactivé';
    }

    return 'Identifiants incorrects';
  }

  /** Extrait le message d'erreur renvoye par Laravel, s'il y en a un. */
  private extractServerMessage(error: HttpErrorResponse): string | null {
    const body = error.error as LaravelErrorBody | null;

    return body?.errors?.['email']?.[0] ?? body?.message ?? null;
  }
}
