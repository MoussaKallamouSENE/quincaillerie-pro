import { HttpClient, HttpResourceRequest } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { environment } from '../../environments/environment';
import {
  Product,
  ProductDraft,
  ProductQuery,
  ProductResponse,
  ProductsPage,
} from './product.model';

/**
 * Client HTTP du catalogue produits.
 *
 * Les routes correspondent a `backend/routes/api.php` :
 * - `GET /api/products` (filtres `search`, `category_id`, `unit_id`, `low_stock`)
 * - `POST /api/products`
 *
 * L'URL de base vient de `environment.apiUrl` (`http://localhost:8000/api` en
 * developpement) : une seule variable a changer pour pointer ailleurs.
 */
@Service()
export class ProductService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** URL de la collection produits. */
  readonly productsUrl = `${this.baseUrl}/products`;

  /**
   * Liste paginee des produits.
   *
   * @param query Filtres appliques par l'API (`search`, `low_stock`, ...).
   */
  getProducts(query: ProductQuery = {}): Observable<ProductsPage> {
    return this.http.get<ProductsPage>(this.productsUrl, { params: toParams(query) });
  }

  /**
   * Cree un produit dans le catalogue.
   *
   * @param draft Donnees du nouveau produit.
   */
  createProduct(draft: ProductDraft): Observable<Product> {
    return this.http
      .post<ProductResponse>(this.productsUrl, draft)
      .pipe(map((response) => response.data));
  }

  /**
   * Descripteur de requete `GET /api/products`, consomme par `httpResource`.
   *
   * Les filtres restent dans cette couche : le composant consomme une
   * ressource, pas une URL.
   */
  listRequest(query: ProductQuery = {}): HttpResourceRequest {
    return {
      url: this.productsUrl,
      params: toParams(query),
    };
  }
}

/** Convertit un filtre de recherche en parametres de requete HTTP. */
function toParams(query: ProductQuery): Record<string, string | number | boolean> {
  const params: Record<string, string | number | boolean> = {};

  if (query.search) {
    params['search'] = query.search;
  }

  if (query.category_id !== undefined) {
    params['category_id'] = query.category_id;
  }

  if (query.unit_id !== undefined) {
    params['unit_id'] = query.unit_id;
  }

  if (query.low_stock !== undefined) {
    params['low_stock'] = query.low_stock ? 1 : 0;
  }

  if (query.per_page !== undefined) {
    params['per_page'] = query.per_page;
  }

  if (query.page !== undefined) {
    params['page'] = query.page;
  }

  return params;
}
