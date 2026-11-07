import { HttpClient } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { AuthUser } from '../core/auth/auth.model';

export interface CompanySettings { business_name: string; phone: string; email: string; address: string; invoice_footer: string; currency: 'XOF'; logo_path: string | null; }
@Service()
export class SettingsService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  getCompany(): Observable<{ settings: CompanySettings }> { return this.http.get<{ settings: CompanySettings }>(`${this.baseUrl}/settings/company`); }
  saveCompany(value: Omit<CompanySettings, 'currency' | 'logo_path'>): Observable<{ settings: CompanySettings; message: string }> {
    return this.http.put<{ settings: CompanySettings; message: string }>(`${this.baseUrl}/settings/company`, value);
  }
  uploadLogo(file: File): Observable<{ settings: CompanySettings; message: string }> {
    const form = new FormData(); form.append('logo', file);
    return this.http.post<{ settings: CompanySettings; message: string }>(`${this.baseUrl}/settings/company/logo`, form);
  }
  getProfile(): Observable<{ user: Pick<AuthUser, 'id' | 'name' | 'email'> & { phone?: string | null } }> { return this.http.get<{ user: Pick<AuthUser, 'id' | 'name' | 'email'> & { phone?: string | null } }>(`${this.baseUrl}/profile`); }
  updateProfile(value: { name: string; email: string; phone: string }): Observable<{ user: AuthUser; message: string }> {
    return this.http.put<{ user: AuthUser; message: string }>(`${this.baseUrl}/profile`, value);
  }
  changePassword(value: { current_password: string; password: string; password_confirmation: string }): Observable<{ message: string }> {
    return this.http.put<{ message: string }>(`${this.baseUrl}/profile/password`, value);
  }
}
