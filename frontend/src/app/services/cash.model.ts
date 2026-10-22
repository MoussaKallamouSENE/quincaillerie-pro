/**
 * Contrats de l'API des sessions de caisse Laravel (`backend/routes/api.php`).
 *
 * Ces types reproduisent fidelement les reponses de `CashSessionController` :
 * - `GET /api/cash-sessions/current` -> 200 `{ data: CashSession | null }`
 * - `POST /api/cash-sessions/open`   -> 201 `{ data: CashSession }`
 * - `POST /api/cash-sessions/close`  -> 200 `{ data: CashSession }`
 *
 * Les montants sont des chaines : le backend les formate avec deux decimales
 * (cf. `CashSessionResource`).
 */

/** Statut possible d'une session de caisse. */
export type CashSessionStatus = 'open' | 'closed';

/** Session de caisse d'un vendeur. */
export interface CashSession {
  id: number;
  user_id: number;
  /** Fond de caisse initial, en FCFA. */
  opening_balance: string;
  /** Montant compte a la cloture, `null` tant que la session est ouverte. */
  closing_balance: string | null;
  status: CashSessionStatus;
  opened_at: string | null;
  closed_at: string | null;
  /** Especes encaissees pendant la session (ventes reglees en especes). */
  cash_received: string;
  /** Depenses payees pendant la session, retirees du tiroir. */
  expenses_total: string;
  /** Theorique du tiroir : fond de caisse + especes encaissees - depenses. */
  expected_cash: string;
  /**
   * Ecart entre le comptage et le solde theorique.
   *
   * Positif = excedent de caisse, negatif = manque de cash.
   */
  difference: string | null;
  /** Ecart entre le comptage et le seul fond de caisse initial. */
  opening_difference: string | null;
}

/** Reponse de `GET /api/cash-sessions/current`. */
export interface CurrentCashSessionResponse {
  data: CashSession | null;
}

/** Reponse des routes d'ouverture et de cloture. */
export interface CashSessionResponse {
  data: CashSession;
}

/** Donnees d'ouverture d'une session de caisse. */
export interface OpenCashSessionPayload {
  opening_balance: number;
}

/** Donnees de cloture d'une session de caisse. */
export interface CloseCashSessionPayload {
  closing_balance: number;
}
