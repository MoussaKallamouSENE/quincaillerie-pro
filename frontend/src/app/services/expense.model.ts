/**
 * Contrats de l'API des dépenses Laravel (`backend/routes/api.php`).
 *
 * Ces types reproduisent fidèlement les réponses de `ExpenseController` :
 * - `GET /api/expenses`  -> 200 `{ data: Expense[], meta: ExpensesMeta }`
 * - `POST /api/expenses` -> 201 `{ data: Expense }`
 *
 * Les montants sont des chaînes : le backend les formate avec deux décimales
 * (cf. `ExpenseResource`).
 */

/** Dépense payée depuis le tiroir de la caisse. */
export interface Expense {
  id: number;
  /** Session de caisse qui a supporté la dépense. */
  cash_session_id: number | null;
  /** Auteur de la dépense. */
  user_id: number;
  /** Motif de la dépense. */
  name: string;
  /** Montant de la dépense, en FCFA. */
  amount: string;
  /** Date de la dépense (`YYYY-MM-DD`). */
  date: string | null;
  created_at: string | null;
  updated_at: string | null;
}

/** Totaux renvoyés avec la liste des dépenses de la session. */
export interface ExpensesMeta {
  /** Session de caisse courante. */
  cash_session_id: number;
  /** Nombre de dépenses de la session. */
  count: number;
  /** Montant total des dépenses de la session, en FCFA. */
  total: string;
}

/** Réponse de `GET /api/expenses`. */
export interface ExpensesPage {
  data: Expense[];
  meta: ExpensesMeta;
}

/** Réponse de `POST /api/expenses`. */
export interface ExpenseResponse {
  data: Expense;
}

/** Données de création d'une dépense. */
export interface ExpenseDraft {
  /** Motif de la dépense. */
  name: string;
  /** Montant payé, en FCFA. */
  amount: number;
}
