/**
 * Contrats de l'API fournisseurs Laravel (`backend/routes/api.php`).
 *
 * Ces types reproduisent fidelement les reponses de `SupplierController` :
 * - `GET /api/suppliers` -> 200 `{ data: Supplier[], meta: SuppliersMeta, links: ... }`
 * - `GET|POST|PUT /api/suppliers` -> 200|201 `{ data: Supplier }`
 *
 * Les noms de champs conservent la casse `snake_case` du backend (convention
 * du projet, cf. `is_active` dans `auth.model.ts`).
 */

/** Fournisseur de la quincaillerie. */
export interface Supplier {
  id: number;
  name: string;
  contact_person: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  is_active: boolean;
}

/** Pagination renvojee par Laravel dans `meta`. */
export interface SuppliersMeta {
  current_page: number;
  from: number | null;
  last_page: number;
  per_page: number;
  to: number | null;
  total: number;
}

/** Page de fournisseurs renvoyee par `GET /api/suppliers`. */
export interface SuppliersPage {
  data: Supplier[];
  meta: SuppliersMeta;
}

/**
 * Fournisseur unique renvoye par l'API.
 *
 * Laravel enveloppe les ressources uniques dans une cle `data`, comme les
 * collections.
 */
export interface SupplierResponse {
  data: Supplier;
}

/** Filtres acceptes par `GET /api/suppliers`. */
export interface SupplierQuery {
  search?: string;
  is_active?: boolean;
  per_page?: number;
  page?: number;
}

/**
 * Donnees d'un nouveau fournisseur, envoyees a `POST /api/suppliers`.
 */
export interface SupplierDraft {
  name: string;
  contact_person: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
}
