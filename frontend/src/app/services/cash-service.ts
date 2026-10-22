import { HttpClient, HttpResourceRequest } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { environment } from '../../environments/environment';
import {
  CashSession,
  CashSessionResponse,
  CloseCashSessionPayload,
  CurrentCashSessionResponse,
  OpenCashSessionPayload,
} from './cash.model';

/**
 * Client HTTP des sessions de caisse.
 *
 * Les routes correspondent a `backend/routes/api.php` :
 * - `GET /api/cash-sessions/current` -> session ouverte du vendeur, ou `null`
 * - `POST /api/cash-sessions/open`   -> ouvre une session (fond de caisse)
 * - `POST /api/cash-sessions/close`  -> cloture la session (montant compte)
 *
 * L'URL de base vient de `environment.apiUrl` (`http://localhost:8000/api` en
 * developpement) : une seule variable a changer pour pointer ailleurs.
 */
@Service()
export class CashService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** URL de la collection sessions de caisse. */
  readonly cashSessionsUrl = `${this.baseUrl}/cash-sessions`;

  /**
   * Session de caisse actuellement ouverte par le vendeur connecte.
   *
   * Renvoie `null` quand aucune session n'est ouverte : l'interface affiche
   * alors la modale d'ouverture.
   */
  getCurrentSession(): Observable<CashSession | null> {
    return this.http
      .get<CurrentCashSessionResponse>(`${this.cashSessionsUrl}/current`)
      .pipe(map((response) => response.data));
  }

  /**
   * Ouvre une session de caisse.
   *
   * @param openingBalance Fond de caisse initial, en FCFA.
   */
  openSession(openingBalance: number): Observable<CashSession> {
    const payload: OpenCashSessionPayload = { opening_balance: openingBalance };

    return this.http
      .post<CashSessionResponse>(`${this.cashSessionsUrl}/open`, payload)
      .pipe(map((response) => response.data));
  }

  /**
   * Cloture la session de caisse ouverte.
   *
   * @param closingBalance Montant d'especes compte dans le tiroir.
   */
  closeSession(closingBalance: number): Observable<CashSession> {
    const payload: CloseCashSessionPayload = { closing_balance: closingBalance };

    return this.http
      .post<CashSessionResponse>(`${this.cashSessionsUrl}/close`, payload)
      .pipe(map((response) => response.data));
  }

  /**
   * Desripteur de requete `GET /api/cash-sessions/current`, consomme par
   * `httpResource`.
   */
  currentRequest(): HttpResourceRequest {
    return { url: `${this.cashSessionsUrl}/current` };
  }
}
