/**
 * Contrats des listes de reference du catalogue Laravel.
 *
 * Ces types reproduisent fidelement les reponses de `CategoryController` et
 * `UnitController` :
 * - `GET /api/categories` -> 200 `{ data: CatalogCategory[] }`
 * - `GET /api/units` -> 200 `{ data: CatalogUnit[] }`
 *
 * Les noms de champs conservent la casse `snake_case` du backend (convention
 * du projet, cf. `is_active` dans `auth.model.ts`).
 */

/** Categorie du catalogue, liste de reference. */
export interface CatalogCategory {
  id: number;
  name: string;
  description: string | null;
  parent_id: number | null;
  parent: { id: number; name: string } | null;
  products_count?: number;
  is_active: boolean;
}

/** Donnees envoyees a l'API pour creer ou modifier une categorie. */
export interface CategoryDraft {
  name: string;
  description: string | null;
  parent_id: number | null;
  is_active: boolean;
}

/** Reponse unitaire de l'API categories. */
export interface CategoryResponse {
  data: CatalogCategory;
}

/** Unite de mesure du catalogue, liste de reference. */
export interface CatalogUnit {
  id: number;
  name: string;
  symbol: string;
  description: string | null;
  is_active: boolean;
}

/** Liste des categories du catalogue. */
export interface CategoryList {
  data: CatalogCategory[];
}

/** Liste des unites de mesure du catalogue. */
export interface UnitList {
  data: CatalogUnit[];
}
