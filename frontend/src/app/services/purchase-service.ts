import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface PurchaseItemDraft {
  product_id: number;
  quantity: number;
  unit_price: number;
}

export interface PurchaseDraft {
  supplier_id: number;
  items: PurchaseItemDraft[];
}

export interface PurchaseItem {
  id: number;
  product_id: number;
  quantity: number;
  unit_price: number;
  subtotal: number;
  product?: any;
}

export interface Purchase {
  id: number;
  supplier_id: number;
  user_id: number | null;
  reference: string;
  total_amount: number | string;
  status: 'pending' | 'received' | string;
  received_at: string | null;
  created_at: string | null;
  supplier?: any;
  user?: any;
  items?: PurchaseItem[];
}

@Injectable({ providedIn: 'root' })
export class PurchaseService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  getPurchases(page: number = 1, perPage: number = 15): Observable<any> {
    return this.http.get(`${this.baseUrl}/purchases`, {
      params: {
        page,
        per_page: perPage,
      },
    });
  }

  getPurchase(id: number): Observable<Purchase> {
    return this.http.get<Purchase>(`${this.baseUrl}/purchases/${id}`);
  }

  createPurchase(draft: PurchaseDraft): Observable<Purchase> {
    return this.http.post<Purchase>(`${this.baseUrl}/purchases`, draft);
  }

  receivePurchase(id: number): Observable<any> {
    return this.http.post(`${this.baseUrl}/purchases/${id}/receive`, {});
  }

  cancelPurchase(id: number): Observable<{ message: string; purchase: Purchase }> {
    return this.http.post<{ message: string; purchase: Purchase }>(
      `${this.baseUrl}/purchases/${id}/cancel`,
      {},
    );
  }
}
