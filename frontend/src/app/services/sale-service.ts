import { HttpClient, HttpResourceRequest } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { environment } from '../../environments/environment';
import {
  ManualPaymentQuery,
  ManualPaymentsResponse,
  Sale,
  SalePayload,
  SalePaymentPayload,
  SaleQuery,
  SaleResponse,
  SalesPage,
} from './sale.model';

/**
 * Client HTTP des ventes.
 *
 * Les routes correspondent a `backend/routes/api.php` :
 * - `GET /api/sales`      -> historique pagine des ventes
 * - `GET /api/sales/{id}` -> detail d'une vente
 * - `POST /api/sales`     -> enregistre une vente et decremente le stock
 *
 * L'URL de base vient de `environment.apiUrl` (`http://localhost:8000/api` en
 * developpement) : une seule variable a changer pour pointer ailleurs.
 */
@Service()
export class SaleService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** URL de la collection ventes. */
  readonly salesUrl = `${this.baseUrl}/sales`;

  /** URL de la file de verification des paiements manuels. */
  readonly manualPaymentsUrl = `${this.baseUrl}/manual-payments`;

  /**
   * Historique pagine des ventes.
   *
   * Chaque vente est accompagnee de son client, de son vendeur, de ses lignes
   * et de ses paiements : l'historique s'affiche donc sans requete
   * supplementaire.
   *
   * @param query Filtres appliques par l'API (`search`, `payment_status`).
   */
  getSales(query: SaleQuery = {}): Observable<SalesPage> {
    return this.http.get<SalesPage>(this.salesUrl, { params: toParams(query) });
  }

  /**
   * Recupere une vente par son identifiant.
   *
   * @param id Identifiant de la vente.
   */
  getSale(id: number): Observable<Sale> {
    return this.http
      .get<SaleResponse>(`${this.salesUrl}/${id}`)
      .pipe(map((response) => response.data));
  }

  /**
   * Enregistre une vente.
   *
   * Le backend decremente le stock des produits vendus dans la meme
   * transaction : si une ligne echoue, rien n'est ecrit.
   *
   * @param payload Lignes vendues, client et reglement.
   */
  createSale(payload: SalePayload): Observable<Sale> {
    return this.http
      .post<SaleResponse>(this.salesUrl, payload)
      .pipe(map((response) => response.data));
  }

  /**
   * Enregistre un règlement ultérieur sur une vente à crédit.
   *
   * @param saleId Identifiant de la vente.
   * @param payload Montant, mode de paiement et référence éventuelle.
   */
  addPayment(saleId: number, payload: SalePaymentPayload): Observable<Sale> {
    return this.http
      .post<SaleResponse>(`${this.salesUrl}/${saleId}/payments`, payload)
      .pipe(map((response) => response.data));
  }

  /**
   * Liste les paiements manuels, en attente par defaut.
   *
   * @param query Filtres appliques par l'API (`status`, `method`, `search`).
   */
  getManualPayments(query: ManualPaymentQuery = {}): Observable<ManualPaymentsResponse> {
    return this.http.get<ManualPaymentsResponse>(this.manualPaymentsUrl, {
      params: toManualPaymentParams(query),
    });
  }

  /**
   * Confirme un paiement Wave ou Orange Money en attente.
   *
   * @param paymentId Identifiant du paiement manuel.
   */
  confirmManualPayment(paymentId: number): Observable<Sale> {
    return this.http
      .post<SaleResponse>(`${this.manualPaymentsUrl}/${paymentId}/confirm`, {})
      .pipe(map((response) => response.data));
  }

  /**
   * Rejette un paiement Wave ou Orange Money en attente.
   *
   * @param paymentId Identifiant du paiement manuel.
   * @param reason Motif obligatoire du rejet.
   */
  rejectManualPayment(paymentId: number, reason: string): Observable<Sale> {
    return this.http
      .post<SaleResponse>(`${this.manualPaymentsUrl}/${paymentId}/reject`, { reason })
      .pipe(map((response) => response.data));
  }

  /**
   * Descripteur de requete `GET /api/manual-payments`.
   *
   * @param query Filtres appliques par l'API, statut `pending` par defaut.
   */
  manualPaymentsRequest(query: ManualPaymentQuery = {}): HttpResourceRequest {
    return {
      url: this.manualPaymentsUrl,
      params: toManualPaymentParams(query),
    };
  }

  /**
   * Telecharge la facture PDF d'une vente.
   *
   * `responseType: 'blob'` empeche Angular de deserialiser la reponse : les
   * octets du PDF sont transmis tels quels au navigateur.
   *
   * @param saleId Identifiant de la vente.
   */
  downloadInvoice(saleId: number): Observable<Blob> {
    return this.http.get(`${this.salesUrl}/${saleId}/invoice`, { responseType: 'blob' });
  }

  /**
   * Descripteur de requete `GET /api/sales`, consomme par `httpResource`.
   *
   * Les filtres restent dans cette couche : le composant consomme une
   * ressource, pas une URL.
   */
  listRequest(query: SaleQuery = {}): HttpResourceRequest {
    return {
      url: this.salesUrl,
      params: toParams(query),
    };
  }
}

/** Convertit les filtres de la file manuelle en parametres HTTP. */
function toManualPaymentParams(query: ManualPaymentQuery): Record<string, string> {
  const params: Record<string, string> = {
    status: query.status ?? 'pending',
  };

  if (query.method) {
    params['method'] = query.method;
  }

  if (query.search) {
    params['search'] = query.search;
  }

  if (query.per_page !== undefined) {
    params['per_page'] = String(query.per_page);
  }

  if (query.page !== undefined) {
    params['page'] = String(query.page);
  }

  return params;
}

/** Convertit un filtre de recherche en parametres de requete HTTP. */
function toParams(query: SaleQuery): Record<string, string | number> {
  const params: Record<string, string | number> = {};

  if (query.search) {
    params['search'] = query.search;
  }

  if (query.payment_status) {
    params['payment_status'] = query.payment_status;
  }

  if (query.cash_session_id !== undefined) {
    params['cash_session_id'] = query.cash_session_id;
  }

  if (query.per_page !== undefined) {
    params['per_page'] = query.per_page;
  }

  if (query.page !== undefined) {
    params['page'] = query.page;
  }

  return params;
}
