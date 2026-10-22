import { Service, signal } from '@angular/core';
import { Toast, ToastType } from './toast.model';

/** Duree d'affichage d'une notification, en millisecondes. */
const DEFAULT_DURATION = 4000;

/**
 * File de notifications affichees a l'utilisateur.
 *
 * Le service est la source de verite unique des toasts : n'importe quel
 * composant peut appeler `success()`, `error()` ou `info()` sans connaitre le
 * composant qui les affiche. Le conteneur `<app-toast-container />`, monte
 * dans la coquille de l'application, se contente de lire `toasts()`.
 */
@Service()
export class ToastService {
  private readonly _toasts = signal<Toast[]>([]);

  /** Identifiant du prochain toast, incremente a chaque creation. */
  private nextId = 0;

  /** Notifications en cours d'affichage, de la plus ancienne a la plus recente. */
  readonly toasts = this._toasts.asReadonly();

  /** Affiche une notification de succes. */
  success(message: string, duration: number = DEFAULT_DURATION): void {
    this.show(message, 'success', duration);
  }

  /** Affiche une notification d'erreur. */
  error(message: string, duration: number = DEFAULT_DURATION): void {
    this.show(message, 'error', duration);
  }

  /** Affiche une notification d'information. */
  info(message: string, duration: number = DEFAULT_DURATION): void {
    this.show(message, 'info', duration);
  }

  /**
   * Affiche une notification et programme sa fermeture.
   *
   * @param message Message affiche a l'utilisateur.
   * @param type Nature de la notification.
   * @param duration Duree d'affichage en millisecondes.
   */
  show(message: string, type: ToastType = 'info', duration: number = DEFAULT_DURATION): void {
    const id = ++this.nextId;

    this._toasts.update((toasts) => [...toasts, { id, message, type }]);

    setTimeout(() => this.dismiss(id), duration);
  }

  /** Ferme une notification avant la fin de son delai d'affichage. */
  dismiss(id: number): void {
    this._toasts.update((toasts) => toasts.filter((toast) => toast.id !== id));
  }

  /** Ferme toutes les notifications affichees. */
  clear(): void {
    this._toasts.set([]);
  }
}
