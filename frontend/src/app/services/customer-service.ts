import { HttpClient, HttpResourceRequest } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { environment } from '../../environments/environment';
import {
  Customer,
  CustomerDraft,
  CustomerQuery,
  CustomerResponse,
  CustomersPage,
} from './customer.model';

/**
 * Client HTTP du fichier clients.
 *
 * Les routes correspondent a `backend/routes/api.php` :
 * - `GET /api/customers` (filtres `search`, `is_active`)
 * - `GET /api/customers/{id}`
 * - `POST /api/customers`
 * - `PUT /api/customers/{id}`
 * - `DELETE /api/customers/{id}`
 *
 * L'URL de base vient de `environment.apiUrl` (`http://localhost:8000/api` en
 * developpement) : une seule variable a changer pour pointer ailleurs.
 */
@Service()
export class CustomerService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** URL de la collection clients. */
  readonly customersUrl = `${this.baseUrl}/customers`;

  /**
   * Liste paginee des clients.
   *
   * @param query Filtres appliques par l'API (`search`, `is_active`, ...).
   */
  getCustomers(query: CustomerQuery = {}): Observable<CustomersPage> {
    return this.http.get<CustomersPage>(this.customersUrl, { params: toParams(query) });
  }

  /**
   * Recupere un client par son identifiant.
   *
   * @param id Identifiant du client.
   */
  getCustomer(id: number): Observable<Customer> {
    return this.http
      .get<CustomerResponse>(`${this.customersUrl}/${id}`)
      .pipe(map((response) => response.data));
  }

  /**
   * Cree un client.
   *
   * @param draft Donnees du nouveau client.
   */
  createCustomer(draft: CustomerDraft): Observable<Customer> {
    return this.http
      .post<CustomerResponse>(this.customersUrl, draft)
      .pipe(map((response) => response.data));
  }

  /**
   * Met a jour un client.
   *
   * @param id Identifiant du client.
   * @param draft Champs a modifier.
   */
  updateCustomer(id: number, draft: CustomerDraft): Observable<Customer> {
    return this.http
      .put<CustomerResponse>(`${this.customersUrl}/${id}`, draft)
      .pipe(map((response) => response.data));
  }

  /**
   * Supprime un client.
   *
   * @param id Identifiant du client.
   */
  deleteCustomer(id: number): Observable<void> {
    return this.http.delete<void>(`${this.customersUrl}/${id}`);
  }

  /**
   * Descripteur de requete `GET /api/customers`, consomme par `httpResource`.
   *
   * Les filtres restent dans cette couche : le composant consomme une
   * ressource, pas une URL.
   */
  listRequest(query: CustomerQuery = {}): HttpResourceRequest {
    return {
      url: this.customersUrl,
      params: toParams(query),
    };
  }
}

/** Convertit un filtre de recherche en parametres de requete HTTP. */
function toParams(query: CustomerQuery): Record<string, string | number | boolean> {
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
