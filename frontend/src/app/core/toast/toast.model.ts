/**
 * Contrats des notifications affichees a l'utilisateur.
 *
 * Une notification est un message court, temporaire, affiche dans le coin de
 * l'ecran pour confirmer (ou signaler l'echec d') une action.
 */

/** Nature de la notification. */
export type ToastType = 'success' | 'error' | 'info';

/** Notification temporaire affichee par le conteneur de toasts. */
export interface Toast {
  /** Identifiant unique, utilise pour la fermeture et le suivi de l'animation. */
  readonly id: number;
  /** Message affiche a l'utilisateur. */
  readonly message: string;
  /** Nature de la notification, pilotee la couleur du toast. */
  readonly type: ToastType;
}
