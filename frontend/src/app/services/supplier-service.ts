import { HttpClient, HttpResourceRequest } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { environment } from '../../environments/environment';
import {
  Supplier,
  SupplierDraft,
  SupplierQuery,
  SupplierResponse,
  SuppliersPage,
} from './supplier.model';

/**
 * Client HTTP du fichier fournisseurs.
 *
 * Les routes correspondent a `backend/routes/api.php` :
 * - `GET /api/suppliers` (filtres `search`, `is_active`)
 * - `GET /api/suppliers/{id}`
 * - `POST /api/suppliers`
 * - `PUT /api/suppliers/{id}`
 * - `DELETE /api/suppliers/{id}`
 *
 * L'URL de base vient de `environment.apiUrl` (`http://localhost:8000/api` en
 * developpement) : une seule variable a changer pour pointer ailleurs.
 */
@Service()
export class SupplierService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** URL de la collection fournisseurs. */
  readonly suppliersUrl = `${this.baseUrl}/suppliers`;

  /**
   * Liste paginee des fournisseurs.
   *
   * @param query Filtres appliques par l'API (`search`, `is_active`, ...).
   */
  getSuppliers(query: SupplierQuery = {}): Observable<SuppliersPage> {
    return this.http.get<SuppliersPage>(this.suppliersUrl, { params: toParams(query) });
  }

  /**
   * Recupere un fournisseur par son identifiant.
   *
   * @param id Identifiant du fournisseur.
   */
  getSupplier(id: number): Observable<Supplier> {
    return this.http
      .get<SupplierResponse>(`${this.suppliersUrl}/${id}`)
      .pipe(map((response) => response.data));
  }

  /**
   * Cree un fournisseur.
   *
   * @param draft Donnees du nouveau fournisseur.
   */
  createSupplier(draft: SupplierDraft): Observable<Supplier> {
    return this.http
      .post<SupplierResponse>(this.suppliersUrl, draft)
      .pipe(map((response) => response.data));
  }

  /**
   * Met a jour un fournisseur.
   *
   * @param id Identifiant du fournisseur.
   * @param draft Champs a modifier.
   */
  updateSupplier(id: number, draft: SupplierDraft): Observable<Supplier> {
    return this.http
      .put<SupplierResponse>(`${this.suppliersUrl}/${id}`, draft)
      .pipe(map((response) => response.data));
  }

  /**
   * Supprime un fournisseur.
   *
   * @param id Identifiant du fournisseur.
   */
  deleteSupplier(id: number): Observable<void> {
    return this.http.delete<void>(`${this.suppliersUrl}/${id}`);
  }

  /**
   * Descripteur de requete `GET /api/suppliers`, consomme par `httpResource`.
   *
   * Les filtres restent dans cette couche : le composant consomme une
   * ressource, pas une URL.
   */
  listRequest(query: SupplierQuery = {}): HttpResourceRequest {
    return {
      url: this.suppliersUrl,
      params: toParams(query),
    };
  }
}

/** Convertit un filtre de recherche en parametres de requete HTTP. */
function toParams(query: SupplierQuery): Record<string, string | number | boolean> {
  const params: Record<string, string | number | boolean> = {};

  if (query.search) {
    params['search'] = query.search;
  }

  if (query.is_active !== undefined) {
    params['is_active'] = query.is_active ? 1 : 0;
  }

  if (query.per_page !== undefined) {
    params['per_page'] = query.per_page;
  }

  if (query.page !== undefined) {
    params['page'] = query.page;
  }

  return params;
}
