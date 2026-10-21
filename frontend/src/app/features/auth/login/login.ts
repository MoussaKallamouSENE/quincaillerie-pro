import { Component, inject, signal } from '@angular/core';
import { FormField, email, form, required, submit } from '@angular/forms/signals';
import { ActivatedRoute, Router } from '@angular/router';
import { AuthStore } from '../../../core/auth/auth-store';
import { extractErrorMessage } from '../../../core/http/api-error';

@Component({
  selector: 'app-login',
  imports: [FormField],
  templateUrl: './login.html',
  styleUrl: './login.css',
})
export class Login {
  private readonly auth = inject(AuthStore);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  /** Modele source du formulaire (approche model-driven des Signal Forms). */
  protected readonly model = signal({
    email: '',
    password: '',
  });

  /** Formulaire de connexion. */
  protected readonly form = form(this.model, (schema) => {
    required(schema.email, { message: "L'adresse e-mail est obligatoire." });
    email(schema.email, { message: "L'adresse e-mail est invalide." });
    required(schema.password, { message: 'Le mot de passe est obligatoire.' });
  });

  /** Message d'erreur renvoye par l'API (identifiants incorrects, reseau, ...). */
  protected readonly errorMessage = signal<string | null>(null);

  /** Vrai pendant l'appel a `POST /api/login`. */
  protected readonly submitting = signal(false);

  protected onSubmit(): void {
    submit(this.form, async () => {
      this.submitting.set(true);
      this.errorMessage.set(null);

      try {
        await this.auth.signIn({
          email: this.model().email,
          password: this.model().password,
        });

        const redirectUrl = this.route.snapshot.queryParamMap.get('redirectUrl') ?? '/';
        await this.router.navigateByUrl(redirectUrl);
      } catch (error) {
        this.errorMessage.set(extractErrorMessage(error, 'Connexion impossible.'));
      } finally {
        this.submitting.set(false);
      }
    });
  }
}
