import { HttpClient, HttpResourceRequest } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { CategoryList, UnitList } from './reference.model';

/**
 * Client HTTP des listes de reference du catalogue.
 *
 * Les routes correspondent a `backend/routes/api.php` :
 * - `GET /api/categories`
 * - `GET /api/units`
 *
 * Ces listes alimentent les selections du formulaire de creation d'un produit.
 */
@Service()
export class ReferenceService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** URL de la collection categories. */
  readonly categoriesUrl = `${this.baseUrl}/categories`;

  /** URL de la collection unites. */
  readonly unitsUrl = `${this.baseUrl}/units`;

  /** Liste des categories du catalogue. */
  getCategories(): Observable<CategoryList> {
    return this.http.get<CategoryList>(this.categoriesUrl);
  }

  /** Liste des unites de mesure du catalogue. */
  getUnits(): Observable<UnitList> {
    return this.http.get<UnitList>(this.unitsUrl);
  }

  /**
   * Descripteur de requete `GET /api/categories`, consomme par `httpResource`.
   */
  categoriesRequest(): HttpResourceRequest {
    return { url: this.categoriesUrl };
  }

  /**
   * Descripteur de requete `GET /api/units`, consomme par `httpResource`.
   */
  unitsRequest(): HttpResourceRequest {
    return { url: this.unitsUrl };
  }
}
