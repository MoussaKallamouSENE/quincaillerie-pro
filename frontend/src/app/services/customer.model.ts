/**
 * Contrats de l'API clients Laravel (`backend/routes/api.php`).
 *
 * Ces types reproduisent fidelement les reponses de `CustomerController` :
 * - `GET /api/customers` -> 200 `{ data: Customer[], meta: CustomersMeta, links: ... }`
 * - `GET|POST|PUT /api/customers` -> 200|201 `{ data: Customer }`
 *
 * Les noms de champs conservent la casse `snake_case` du backend (convention
 * du projet, cf. `is_active` dans `auth.model.ts`). Les montants sont des
 * chaines car le modele `Customer` les caste en `decimal:2`.
 */

/** Client de la quincaillerie. */
export interface Customer {
  id: number;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  /** Plafond de credit accorde au client, en FCFA. */
  credit_limit: string;
  /** Dette actuelle du client, en FCFA. */
  outstanding_balance: string;
  is_active: boolean;
}

/** Pagination renvojee par Laravel dans `meta`. */
export interface CustomersMeta {
  current_page: number;
  from: number | null;
  last_page: number;
  per_page: number;
  to: number | null;
  total: number;
}

/** Page de clients renvoyee par `GET /api/customers`. */
export interface CustomersPage {
  data: Customer[];
  meta: CustomersMeta;
}

/**
 * Client unique renvoye par l'API.
 *
 * Laravel enveloppe les ressources uniques dans une cle `data`, comme les
 * collections.
 */
export interface CustomerResponse {
  data: Customer;
}

/** Filtres acceptes par `GET /api/customers`. */
export interface CustomerQuery {
  search?: string;
  is_active?: boolean;
  per_page?: number;
  page?: number;
}

/**
 * Donnees d'un nouveau client, envoyees a `POST /api/customers`.
 *
 * Le solde du client (`outstanding_balance`) n'est pas envoye : il est calcule
 * par le backend a partir des ventes et des reglements.
 */
export interface CustomerDraft {
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  credit_limit: number;
}
