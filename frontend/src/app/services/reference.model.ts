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
  is_active: boolean;
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
