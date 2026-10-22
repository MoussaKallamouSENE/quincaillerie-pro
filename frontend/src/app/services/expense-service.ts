import { HttpClient, HttpResourceRequest } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { environment } from '../../environments/environment';
import { Expense, ExpenseDraft, ExpenseResponse, ExpensesPage } from './expense.model';

/**
 * Client HTTP des dépenses de caisse.
 *
 * Les routes correspondent à `backend/routes/api.php` :
 * - `GET /api/expenses`  -> dépenses de la session de caisse ouverte
 * - `POST /api/expenses` -> enregistre une dépense sur la session ouverte
 *
 * Le backend rattache toujours la dépense à la session ouverte de
 * l'utilisateur : son montant est retiré du tiroir et du solde théorique de la
 * caisse.
 *
 * L'URL de base vient de `environment.apiUrl` (`http://localhost:8000/api` en
 * développement) : une seule variable à changer pour pointer ailleurs.
 */
@Service()
export class ExpenseService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiUrl;

  /** URL de la collection dépenses. */
  readonly expensesUrl = `${this.baseUrl}/expenses`;

  /**
   * Dépenses de la session de caisse ouverte.
   *
   * La liste est accompagnée du total de la session (`meta.total`), le montant
   * qui sera retranché du solde théorique à la clôture.
   */
  getExpenses(): Observable<ExpensesPage> {
    return this.http.get<ExpensesPage>(this.expensesUrl);
  }

  /**
   * Enregistre une dépense sur la session de caisse ouverte.
   *
   * @param draft Motif et montant de la dépense.
   */
  createExpense(draft: ExpenseDraft): Observable<Expense> {
    return this.http
      .post<ExpenseResponse>(this.expensesUrl, draft)
      .pipe(map((response) => response.data));
  }

  /**
   * Descripteur de requête `GET /api/expenses`, consommé par `httpResource`.
   */
  listRequest(): HttpResourceRequest {
    return { url: this.expensesUrl };
  }
}
