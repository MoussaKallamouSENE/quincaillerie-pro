import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

@Injectable({ providedIn: 'root' })
export class StockService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  getMovements(): Observable<any> {
    return this.http.get(`${this.baseUrl}/stock-movements`);
  }

  createMovement(data: any): Observable<any> {
    return this.http.post(`${this.baseUrl}/stock-movements`, data);
  }
}
