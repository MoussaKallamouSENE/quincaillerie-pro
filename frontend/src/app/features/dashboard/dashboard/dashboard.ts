import { DecimalPipe } from '@angular/common';
import { httpResource } from '@angular/common/http';
import { Component, computed, inject } from '@angular/core';
import { extractErrorMessage } from '../../../core/http/api-error';
import { ProductService } from '../../../services/product-service';
import { Product, ProductsPage } from '../../../services/product.model';

/** Statut d'une vente dans le tableau des dernieres ventes. */
type SaleStatus = 'paid' | 'pending';

/** Carte d'indicateur cle de performance affichee en haut du tableau de bord. */
interface KpiCard {
  /** Libelle de l'indicateur. */
  readonly label: string;
  /**
   * Valeur principale, formatee dans le template.
   *
   * `null` lorsque la donnee n'est pas encore disponible (chargement en cours
   * ou requete en echec) : le template affiche alors un tiret.
   */
  readonly value: number | null;
  /** Unite affichee apres la valeur (`FCFA`). */
  readonly unit?: string;
  /** Precision affichee sous la valeur. */
  readonly detail: string;
  /** Trace SVG (Heroicons, 24x24) de l'icone de la carte. */
  readonly icon: string;
  /** Variation affichee dans une pastille (ex. `+12,5 %`). */
  readonly trend?: string;
  /** Classes appliquees a la valeur : rouge pour les alertes. */
  readonly valueClass?: string;
  /** Classes appliquees au conteneur de l'icone. */
  readonly iconClass?: string;
}

/** Point du graphique d'evolution des ventes. */
interface SalesPoint {
  /** Jour de la semaine. */
  readonly day: string;
  /** Chiffre d'affaires du jour, en FCFA. */
  readonly amount: number;
}

/** Vente affichee dans le tableau des dernieres ventes. */
interface RecentSale {
  /** Reference de la vente. */
  readonly reference: string;
  /** Nom du client. */
  readonly client: string;
  /** Montant de la vente, en FCFA. */
  readonly amount: number;
  /** Statut de la vente. */
  readonly status: SaleStatus;
}

/**
 * Donnees de demonstration du tableau de bord.
 *
 * Elles alimentent les cartes KPI, le graphique et le tableau des dernieres
 * ventes tant que les modules `ventes` et `stock` ne sont pas connectes a
 * l'API. Les remplacer par les reelles reponses HTTP au fur et a mesure.
 *
 * Le chiffre d'affaires et les ventes du jour proviennent du module `ventes`,
 * encore inexistant : ils restent simules. Les deux indicateurs de stock sont
 * calcules a partir de l'API catalogue, voir `kpis`.
 */
const MOCK_KPIS: readonly KpiCard[] = [
  {
    label: "Chiffre d'affaires",
    value: 1250000,
    unit: 'FCFA',
    detail: 'ce mois-ci',
    trend: '+12,5 %',
    icon: 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  },
  {
    label: 'Ventes du jour',
    value: 45,
    detail: 'transactions aujourd’hui',
    icon: 'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z',
  },
];

/** Ventes des 7 derniers jours, en FCFA. */
const MOCK_SALES: readonly SalesPoint[] = [
  { day: 'Lun', amount: 145000 },
  { day: 'Mar', amount: 98000 },
  { day: 'Mer', amount: 210000 },
  { day: 'Jeu', amount: 175000 },
  { day: 'Ven', amount: 265000 },
  { day: 'Sam', amount: 320000 },
  { day: 'Dim', amount: 112000 },
];

/** Dernieres ventes enregistrees. */
const MOCK_RECENT_SALES: readonly RecentSale[] = [
  { reference: 'VTE-1042', client: 'Awa Diop', amount: 185000, status: 'paid' },
  { reference: 'VTE-1041', client: 'Michel Kévin', amount: 42500, status: 'pending' },
  { reference: 'VTE-1040', client: 'Fatou Ndiaye', amount: 320000, status: 'paid' },
  { reference: 'VTE-1039', client: 'Boutique Samba', amount: 98500, status: 'paid' },
  { reference: 'VTE-1038', client: 'Ibrahim Sow', amount: 67200, status: 'pending' },
];

@Component({
  selector: 'app-dashboard',
  imports: [DecimalPipe],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css',
})
export class Dashboard {
  private readonly productService = inject(ProductService);

  /**
   * Catalogue complet.
   *
   * Seul le nombre total de references est utilise (`per_page=1` suffit a
   * obtenir la pagination complete).
   */
  protected readonly catalog = httpResource<ProductsPage>(() =>
    this.productService.listRequest({ per_page: 1 }),
  );

  /** Produits dont le stock est passe sous le seuil d'alerte. */
  protected readonly lowStockProducts = httpResource<ProductsPage>(() =>
    this.productService.listRequest({ low_stock: true, per_page: 10 }),
  );

  /** Nombre de references du catalogue, `null` tant que la reponse n'est pas arrivee. */
  protected readonly catalogTotal = computed<number | null>(() => {
    const page = this.catalog.hasValue() ? this.catalog.value() : undefined;

    return page?.meta.total ?? null;
  });

  /** Nombre de produits sous le seuil d'alerte, `null` tant que la reponse n'est pas arrivee. */
  protected readonly lowStockTotal = computed<number | null>(() => {
    const page = this.lowStockProducts.hasValue() ? this.lowStockProducts.value() : undefined;

    return page?.meta.total ?? null;
  });

  /** Produits sous le seuil d'alerte, tels que renvoyes par l'API. */
  protected readonly stockAlerts = computed<Product[]>(() => {
    const page = this.lowStockProducts.hasValue() ? this.lowStockProducts.value() : undefined;

    return page?.data ?? [];
  });

  /** Message d'erreur lisible quand les alertes de stock ne peuvent pas etre chargees. */
  protected readonly lowStockErrorMessage = computed<string | null>(() => {
    const error = this.lowStockProducts.error();

    return error ? extractErrorMessage(error, 'Impossible de charger les alertes de stock.') : null;
  });

  /** Indicateurs cles de performance. */
  protected readonly kpis = computed<KpiCard[]>(() => [
    ...MOCK_KPIS,
    {
      label: 'Produits en stock',
      value: this.catalogTotal(),
      detail: 'références au catalogue',
      icon: 'm21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
    },
    {
      label: 'Alertes de stock bas',
      value: this.lowStockTotal(),
      detail: 'sous le seuil d’alerte',
      icon: 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z',
      valueClass: 'text-red-400',
      iconClass: 'bg-red-500/15 text-red-400 ring-red-500/25',
    },
  ]);

  /** Dernieres ventes enregistrees. */
  protected readonly recentSales = MOCK_RECENT_SALES;

  /** Serie du graphique, avec la hauteur de chaque barre en pourcentage. */
  protected readonly salesChart = computed(() => {
    const max = Math.max(...MOCK_SALES.map((point) => point.amount));

    return MOCK_SALES.map((point) => ({
      ...point,
      height: Math.round((point.amount / max) * 100),
    }));
  });

  /** Relance le chargement des alertes de stock. */
  protected reloadLowStock(): void {
    this.lowStockProducts.reload();
  }
}
