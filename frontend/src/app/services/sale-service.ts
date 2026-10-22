import { HttpClient, HttpResourceRequest } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { environment } from '../../environments/environment';
import { Sale, SalePayload, SaleQuery, SaleResponse, SalesPage } from './sale.model';

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
