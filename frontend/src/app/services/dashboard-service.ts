import { HttpClient } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { DashboardStats } from './dashboard.model';

/**
 * Client HTTP des statistiques du tableau de bord.
 *
 * Route correspondante dans `backend/routes/api.php` :
 * - `GET /api/dashboard` -> statistiques agregees du jour et des 7 derniers
 *   jours.
 *
 * L'URL de base vient de `environment.apiUrl` (`http://localhost:8000/api` en
 * developpement), comme les autres services : une seule variable a changer
 * pour pointer ailleurs.
 */
@Service()
export class DashboardService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** URL des statistiques du tableau de bord. */
  readonly dashboardUrl = `${this.baseUrl}/dashboard`;

  /**
   * Charge les statistiques du tableau de bord.
   *
   * La reponse est renvoyee telle quelle : c'est l'interface qui masque le
   * chiffre d'affaires et le graphique quand le backend les a neutralises pour
   * un employe.
   */
  getStats(): Observable<DashboardStats> {
    return this.http.get<DashboardStats>(this.dashboardUrl);
  }
}
