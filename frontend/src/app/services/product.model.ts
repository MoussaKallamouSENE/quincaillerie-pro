/**
 * Contrats de l'API catalogue Laravel (`backend/routes/api.php`).
 *
 * Ces types reproduisent fidelement les reponses de `ProductController` :
 * - `GET /api/products` -> 200 `{ data: Product[], meta: ProductsMeta, links: ... }`
 *
 * Les noms de champs conservent la casse `snake_case` du backend (convention
 * du projet, cf. `is_active` dans `auth.model.ts`). Les prix sont des chaines
 * car le modele `Product` les caste en `decimal:2`.
 */

/** Categorie d'un produit du catalogue. */
export interface ProductCategory {
  id: number;
  name: string;
}

/** Unite de mesure d'un produit du catalogue. */
export interface ProductUnit {
  id: number;
  name: string;
  symbol: string;
}

/** Produit du catalogue. */
export interface Product {
  id: number;
  sku: string;
  name: string;
  category: ProductCategory;
  unit: ProductUnit;
  purchase_price?: string;
  sale_price: string;
  stock_quantity: number;
  min_stock_alert: number;
  is_active: boolean;
  is_low_stock: boolean;
}

/** Pagination renvojee par Laravel dans `meta`. */
export interface ProductsMeta {
  current_page: number;
  from: number | null;
  last_page: number;
  per_page: number;
  to: number | null;
  total: number;
}

/** Page de produits renvoyee par `GET /api/products`. */
export interface ProductsPage {
  data: Product[];
  meta: ProductsMeta;
}

/**
 * Produit unique renvoye par `POST /api/products` et `GET /api/products/{id}`.
 *
 * Laravel enveloppe les ressources uniques dans une cle `data`, comme les
 * collections.
 */
export interface ProductResponse {
  data: Product;
}

/** Filtres acceptes par `GET /api/products`. */
export interface ProductQuery {
  search?: string;
  category_id?: number;
  unit_id?: number;
  low_stock?: boolean;
  per_page?: number;
  page?: number;
}

/**
 * Donnees d'un nouveau produit, envoyees a `POST /api/products`.
 *
 * Les prix et les quantites sont envoyes en nombre : le backend les stocke
 * en `decimal` et `integer` respectivement.
 */
export interface ProductDraft {
  name: string;
  sku: string;
  category_id: number;
  unit_id: number;
  purchase_price: number;
  sale_price: number;
  stock_quantity: number;
  min_stock_alert: number;
}
