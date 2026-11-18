/**
 * Contrats de l'API des ventes Laravel (`backend/routes/api.php`).
 *
 * Ces types reproduisent fidelement les reponses de `SaleController` :
 * - `GET /api/sales`      -> 200 `{ data: Sale[], meta: ..., links: ... }`
 * - `GET /api/sales/{id}` -> 200 `{ data: Sale }`
 * - `POST /api/sales`     -> 201 `{ data: Sale }`
 *
 * Les montants sont des chaines : le backend les formate avec deux decimales
 * (cf. `SaleResource`).
 */

/**
 * Modes de paiement.
 *
 * `credit` n'est pas un mode de reglement du backend : c'est le mode du POS,
 * qui enregistre la vente sans encaisser (la dette part sur le client).
 */
export type PaymentMethod = 'cash' | 'wave' | 'orange_money' | 'bank_transfer' | 'check' | 'credit';

/** Statut de reglement d'une vente. */
export type PaymentStatus = 'paid' | 'partial' | 'credit';

/** Statut de verification d'un paiement manuel. */
export type PaymentVerificationStatus = 'pending' | 'confirmed' | 'rejected';

/** Statut de paiement manuel filtrable dans la file de verification. */
export type ManualPaymentStatusFilter = PaymentVerificationStatus | 'all';

/** Modes de paiement geres par la file de verification manuelle. */
export type ManualPaymentMethod = Extract<PaymentMethod, 'wave' | 'orange_money'>;

/** Ligne de vente (un produit vendu). */
export interface SaleItem {
  id: number;
  product_id: number;
  product_name: string | null;
  quantity: number;
  unit_price: string;
  subtotal: string;
}

/**
 * Paiement rattache a une vente.
 *
 * Les paiements Wave et Orange Money restent `pending` tant qu'un
 * administrateur ou un gerant ne les a pas confirmes ou rejetes.
 */
export interface SalePayment {
  id: number;
  amount: string;
  method: PaymentMethod;
  reference: string | null;
  status: PaymentVerificationStatus;
  confirmed_at: string | null;
  confirmed_by_id: number | null;
  confirmed_by_name: string | null;
  rejected_at: string | null;
  rejected_by_id: number | null;
  rejected_by_name: string | null;
  rejection_reason: string | null;
  created_at: string | null;
}

/** Vente enregistree a la caisse. */
export interface Sale {
  id: number;
  /** Reference lisible de la vente (`VTE-000001`). */
  reference: string;
  customer_id: number | null;
  customer_name: string | null;
  user_id: number;
  user_name: string | null;
  cash_session_id: number;
  total_amount: string;
  /** Montant des seuls paiements confirmes. */
  paid_amount: string;
  /** Montant des paiements manuels en attente de verification. */
  pending_amount: string;
  /** Montant encore disponible pour un nouveau paiement. */
  available_due: string;
  /** Reste a payer hors paiements en attente, qui ne sont pas comptes comme payes. */
  balance_due: string;
  payment_status: PaymentStatus;
  items: SaleItem[];
  payments: SalePayment[];
  created_at: string | null;
  updated_at: string | null;
}

/** Reponse de `POST /api/sales`. */
export interface SaleResponse {
  data: Sale;
}

/** Pagination renvoyee par Laravel dans `meta`. */
export interface SalesMeta {
  current_page: number;
  from: number | null;
  last_page: number;
  per_page: number;
  to: number | null;
  total: number;
}

/** Page de ventes renvoyee par `GET /api/sales`. */
export interface SalesPage {
  data: Sale[];
  meta: SalesMeta;
}

/** Filtres acceptes par `GET /api/sales`. */
export interface SaleQuery {
  /** Recherche sur la reference de la vente ou le nom du client. */
  search?: string;
  /** Filtre sur le statut de reglement. */
  payment_status?: PaymentStatus;
  /** Filtre sur la session de caisse. */
  cash_session_id?: number;
  per_page?: number;
  page?: number;
}

/** Ligne de vente envoyee a l'API. */
export interface SaleItemPayload {
  product_id: number;
  quantity: number;
}

/** Paiement envoye a l'API. */
export interface SalePaymentPayload {
  method: PaymentMethod;
  amount: number;
  reference?: string;
  /** Cle d'idempotence du reglement, pour eviter un doublon en cas de reessai. */
  idempotency_key?: string;
}

/** Paiement manuel expose a la file de verification. */
export interface ManualPayment {
  id: number;
  sale_id: number;
  sale_reference: string | null;
  customer_name: string | null;
  seller_name: string | null;
  amount: string;
  method: PaymentMethod;
  reference: string | null;
  status: PaymentVerificationStatus;
  confirmed_at: string | null;
  confirmed_by_id: number | null;
  confirmed_by_name: string | null;
  rejected_at: string | null;
  rejected_by_id: number | null;
  rejected_by_name: string | null;
  rejection_reason: string | null;
  created_at: string | null;
  sale: {
    id: number;
    reference: string;
    total_amount: string;
    paid_amount: string;
    pending_amount: string;
    available_due: string;
    balance_due: string;
    payment_status: PaymentStatus;
  } | null;
}

/**
 * Reponse de `GET /api/manual-payments`.
 *
 * `meta` n'est present que lorsque l'API est interrogee avec `page` ou
 * `per_page` : l'API reste ainsi compatible avec les appels non pagines.
 */
export interface ManualPaymentsResponse {
  data: ManualPayment[];
  meta?: SalesMeta;
}

/** Filtres acceptes par `GET /api/manual-payments`. */
export interface ManualPaymentQuery {
  /** Statut demande, `pending` par defaut cote backend. */
  status?: ManualPaymentStatusFilter;
  /** Restreint la file a Wave ou Orange Money. */
  method?: ManualPaymentMethod;
  /** Recherche partielle sur la reference de transaction. */
  search?: string;
  per_page?: number;
  page?: number;
}

/**
 * Vente envoyee a l'API.
 *
 * Le paiement est absent pour une vente entierement a credit : le backend
 * calcule alors la dette du client a partir du total.
 */
export interface SalePayload {
  customer_id: number | null;
  items: SaleItemPayload[];
  payment?: SalePaymentPayload;
  /**
   * Cle generee par la caisse pour rendre la soumission idempotente : une
   * seconde requete avec la meme cle renvoie la vente deja enregistree.
   */
  idempotency_key?: string;
}
