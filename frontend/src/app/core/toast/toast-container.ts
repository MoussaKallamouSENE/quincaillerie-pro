import { Component, inject } from '@angular/core';
import { ToastService } from './toast-service';

/**
 * Conteneur des notifications temporaires.
 *
 * Monte une seule fois dans la coquille de l'application (`layout.html`), il
 * lit la file de `ToastService` et affiche chaque notification dans le coin
 * bas-droit de l'ecran.
 */
@Component({
  selector: 'app-toast-container',
  templateUrl: './toast-container.html',
  styleUrl: './toast-container.css',
})
export class ToastContainer {
  private readonly toastService = inject(ToastService);

  /** Notifications en cours d'affichage. */
  protected readonly toasts = this.toastService.toasts;

  /** Ferme une notification. */
  protected dismiss(id: number): void {
    this.toastService.dismiss(id);
  }
}
