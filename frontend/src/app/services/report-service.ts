import { HttpClient, HttpParams } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export type ReportPeriod = 'today' | 'week' | 'month' | 'year' | 'custom';

export type ReportType =
  | 'sales'
  | 'purchases'
  | 'stock'
  | 'out_of_stock'
  | 'low_stock'
  | 'stock_movements'
  | 'customers'
  | 'suppliers'
  | 'cash_sessions'
  | 'cash_movements'
  | 'expenses';

export interface ReportFilters {
  period: ReportPeriod;
  from?: string;
  to?: string;
  search?: string;
  page?: number;
  per_page?: number;
}

export interface ReportSummary {
  period: { from: string; to: string };
  currency: 'XOF';
  metrics: {
    revenue: number;
    sales_count: number;
    received_purchases: number;
    received_purchases_count: number;
    expenses: number;
    profit: number | null;
    margin: number | null;
  };
  activity: Array<{ bucket: string; amount: number; count: number }>;
  top_products: Array<{ id: number; name: string; sku: string; quantity: number; revenue: number }>;
  notes: string[];
}

export type ReportRow = Record<string, unknown>;

export interface ReportPage {
  type: ReportType | string;
  data: ReportRow[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface ReportTypeOption {
  id: ReportType;
  label: string;
  description: string;
}

export const REPORT_TYPES: readonly ReportTypeOption[] = [
  { id: 'sales', label: 'Ventes', description: 'Chiffre d’affaires et règlements' },
  { id: 'purchases', label: 'Achats', description: 'Commandes et réceptions' },
  { id: 'stock', label: 'Stock', description: 'État et valorisation du catalogue' },
  { id: 'out_of_stock', label: 'Produits en rupture', description: 'Références à réapprovisionner' },
  { id: 'low_stock', label: 'Stock faible', description: 'Références sous le seuil d’alerte' },
  { id: 'stock_movements', label: 'Mouvements de stock', description: 'Entrées, sorties et ajustements' },
  { id: 'customers', label: 'Clients', description: 'Coordonnées et encours' },
  { id: 'suppliers', label: 'Fournisseurs', description: 'Coordonnées et contacts' },
  { id: 'cash_sessions', label: 'Sessions de caisse', description: 'Ouvertures, clôtures et écarts' },
  { id: 'cash_movements', label: 'Mouvements de caisse', description: 'Entrées et sorties du tiroir' },
  { id: 'expenses', label: 'Dépenses', description: 'Sorties d’argent enregistrées' },
];

@Service()
export class ReportService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = `${environment.apiUrl}/reports`;

  summary(filters: ReportFilters): Observable<ReportSummary> {
    return this.http.get<ReportSummary>(`${this.baseUrl}/summary`, { params: this.params(filters) });
  }

  report(type: ReportType, filters: ReportFilters): Observable<ReportPage> {
    return this.http.get<ReportPage>(`${this.baseUrl}/${type}`, { params: this.params(filters) });
  }

  pdf(type: ReportType, filters: ReportFilters): Observable<Blob> {
    return this.http.get(`${this.baseUrl}/${type}/pdf`, {
      params: this.params(filters),
      responseType: 'blob',
    });
  }

  private params(filters: ReportFilters): HttpParams {
    let params = new HttpParams()
      .set('period', filters.period)
      .set('per_page', filters.per_page ?? 25);

    if (filters.from) {
      params = params.set('from', filters.from);
    }
    if (filters.to) {
      params = params.set('to', filters.to);
    }
    if (filters.search) {
      params = params.set('search', filters.search);
    }
    if (filters.page) {
      params = params.set('page', filters.page);
    }

    return params;
  }
}
