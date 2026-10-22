import { PaymentStatus } from './sale.model';

/**
 * Contrats de l'API du tableau de bord Laravel (`backend/routes/api.php`).
 *
 * `GET /api/dashboard` renvoie directement les statistiques agregees, sans
 * enveloppe `data`. Le backend masque le chiffre d'affaires et le graphique
 * (`total_revenue_today` a 0, `sales_chart` vide) quand l'utilisateur connecte
 * possede le role Employe.
 */

/** Point du graphique d'evolution des ventes. */
export interface SalesChartPoint {
  /** Jour au format ISO (`2026-10-16`). */
  date: string;
  /** Libelle court en francais (`ven. 16`). */
  label: string;
  /** Chiffre d'affaires du jour, en FCFA. */
  total_amount: number;
  /** Nombre de ventes du jour. */
  sales_count: number;
}

/** Vente affichee dans le tableau des dernieres ventes. */
export interface DashboardRecentSale {
  id: number;
  /** Reference lisible de la vente (`VTE-000042`). */
  reference: string;
  /** Nom du client, `null` pour une vente au comptant. */
  customer_name: string | null;
  /** Montant de la vente, en FCFA. */
  total_amount: number;
  /** Statut de reglement de la vente. */
  payment_status: PaymentStatus;
  /** Date de la vente, au format ISO. */
  created_at: string | null;
}

/** Statistiques renvoyees par `GET /api/dashboard`. */
export interface DashboardStats {
  /** Chiffre d'affaires du jour, en FCFA (0 pour un employe). */
  total_revenue_today: number;
  /** Nombre de ventes enregistrees aujourd'hui. */
  sales_count_today: number;
  /** Nombre de produits sous leur seuil d'alerte de stock. */
  low_stock_count: number;
  /** Les cinq dernieres ventes. */
  recent_sales: DashboardRecentSale[];
  /** Ventes agregees sur les 7 derniers jours ([] pour un employe). */
  sales_chart: SalesChartPoint[];
}
