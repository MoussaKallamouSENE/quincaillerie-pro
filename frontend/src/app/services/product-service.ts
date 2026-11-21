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
    const formData = new FormData();
    formData.append('name', draft.name);
    formData.append('sku', draft.sku);
    formData.append('category_id', String(draft.category_id));
    formData.append('unit_id', String(draft.unit_id));
    formData.append('purchase_price', String(draft.purchase_price));
    formData.append('sale_price', String(draft.sale_price));
    formData.append('stock_quantity', String(draft.stock_quantity));
    formData.append('min_stock_alert', String(draft.min_stock_alert));
    if (draft.image) {
      formData.append('image', draft.image);
    }

    return this.http
      .post<ProductResponse>(this.productsUrl, formData)
      .pipe(map((response) => response.data));
  }

  updateProduct(id: number, draft: ProductDraft & { remove_image?: boolean }): Observable<Product> {
    const formData = new FormData();
    Object.entries(draft).forEach(([key, value]) => {
      if (value === undefined || value === null) {
        return;
      }
      if (key === 'image' && value instanceof File) {
        formData.append('image', value);
        return;
      }
      if (key === 'remove_image') {
        formData.append('remove_image', value ? '1' : '0');
        return;
      }
      formData.append(key, String(value));
    });

    return this.http
      .put<ProductResponse>(`${this.productsUrl}/${id}`, formData)
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
