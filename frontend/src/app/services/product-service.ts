import { HttpClient, HttpResourceRequest } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { environment } from '../../environments/environment';
import {
  Product,
  ProductDraft,
  ProductQuery,
  ProductResponse,
  ProductsPage,
} from './product.model';

@Injectable({ providedIn: 'root' })
export class ProductService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  readonly productsUrl = `${this.baseUrl}/products`;

  getProducts(query: ProductQuery = {}): Observable<ProductsPage> {
    return this.http.get<ProductsPage>(this.productsUrl, { params: toParams(query) });
  }

  createProduct(draft: ProductDraft): Observable<Product> {
    return this.http
      .post<ProductResponse>(this.productsUrl, draft)
      .pipe(map((response) => response.data));
  }

  listRequest(query: ProductQuery = {}): HttpResourceRequest {
    return {
      url: this.productsUrl,
      params: toParams(query),
    };
  }
}

function toParams(query: ProductQuery): Record<string, string | number | boolean> {
  const params: Record<string, string | number | boolean> = {};

  if (query.search) params['search'] = query.search;
  if (query.category_id !== undefined) params['category_id'] = query.category_id;
  if (query.unit_id !== undefined) params['unit_id'] = query.unit_id;
  if (query.low_stock !== undefined) params['low_stock'] = query.low_stock ? 1 : 0;
  if (query.per_page !== undefined) params['per_page'] = query.per_page;
  if (query.page !== undefined) params['page'] = query.page;

  return params;
}
