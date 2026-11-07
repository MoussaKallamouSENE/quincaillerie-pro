import { DatePipe, DecimalPipe } from '@angular/common';
import { httpResource } from '@angular/common/http';
import { Component, computed, inject } from '@angular/core';
import { rxResource } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../../core/auth/auth-service';
import { extractErrorMessage } from '../../../core/http/api-error';
import { DashboardService } from '../../../services/dashboard-service';
import { DashboardStats } from '../../../services/dashboard.model';
import { Product, ProductsPage } from '../../../services/product.model';
import { ProductService } from '../../../services/product-service';
import { PaymentStatus } from '../../../services/sale.model';

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
  /** Classes appliquees a la valeur : rouge pour les alertes. */
  readonly valueClass?: string;
  /** Classes appliquees au conteneur de l'icone. */
  readonly iconClass?: string;
}

/** Barre du graphique, avec sa hauteur relative au maximum de la serie. */
interface SalesBar {
  /** Jour au format ISO, utilise comme cle de suivi. */
  readonly date: string;
  /** Libelle court du jour (`ven. 16`). */
  readonly label: string;
  /** Chiffre d'affaires du jour, en FCFA. */
  readonly amount: number;
  /** Hauteur de la barre, en pourcentage du maximum de la serie. */
  readonly height: number;
  /** Montant abrege affiche au-dessus de la barre (`142 k`). */
  readonly compactAmount: string;
}

/** Libelles des statuts de reglement d'une vente. */
const STATUS_LABELS: Record<PaymentStatus, string> = {
  paid: 'Payé',
  partial: 'Partiel',
  credit: 'Crédit',
};

/** Couleurs des badges de statut, alignees sur l'historique des ventes. */
const STATUS_CLASSES: Record<PaymentStatus, string> = {
  paid: 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/25',
  partial: 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/25',
  credit: 'bg-red-500/15 text-red-300 ring-1 ring-red-500/25',
};

/**
 * Normalise un role pour le comparer sans tenir compte de la casse ni des
 * accents : le backend renvoie « Employé », l'interface doit aussi reconnaitre
 * la graphie « employe ».
 */
function normalizeRole(role: string): string {
  return role
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase();
}

@Component({
  selector: 'app-dashboard',
  imports: [DatePipe, DecimalPipe, RouterLink],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css',
})
export class Dashboard {
  protected readonly auth = inject(AuthService);
  private readonly dashboardService = inject(DashboardService);
  private readonly productService = inject(ProductService);

  /** Statistiques agregees chargees depuis `GET /api/dashboard`. */
  protected readonly stats = rxResource<DashboardStats, unknown>({
    stream: () => this.dashboardService.getStats(),
  });

  /**
   * Dernieres statistiques valides.
   *
   * `value()` leve une erreur tant que la ressource est en echec : on ne
   * l'appelle donc qu'apres `hasValue()`.
   */
  private readonly statsValue = computed<DashboardStats | undefined>(() =>
    this.stats.hasValue() ? this.stats.value() : undefined,
  );

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

  /** Vrai si l'utilisateur connecte est un employe. */
  protected readonly isEmployee = computed(() =>
    this.auth.roles().some((role) => normalizeRole(role) === 'employe'),
  );

  /** Vrai si le chiffre d'affaires et le graphique doivent etre affiches. */
  protected readonly canViewRevenue = computed(() => !this.isEmployee());

  /** Vrai si l'utilisateur peut acceder aux rapports et aux achats. */
  protected readonly canManageOperations = computed(() => this.auth.hasRole('Admin', 'Gérant'));

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

  /** Message d'erreur lisible quand les statistiques ne peuvent pas etre chargees. */
  protected readonly statsErrorMessage = computed<string | null>(() => {
    const error = this.stats.error();

    return error ? extractErrorMessage(error, 'Impossible de charger les statistiques.') : null;
  });

  /** Message d'erreur lisible quand les alertes de stock ne peuvent pas etre chargees. */
  protected readonly lowStockErrorMessage = computed<string | null>(() => {
    const error = this.lowStockProducts.error();

    return error ? extractErrorMessage(error, 'Impossible de charger les alertes de stock.') : null;
  });

  /**
   * Indicateurs cles de performance.
   *
   * La carte « Chiffre d'affaires » est omise pour un employe : il ne doit pas
   * voir l'argent genere par la quincaillerie.
   */
  protected readonly kpis = computed<KpiCard[]>(() => {
    const stats = this.statsValue();
    const cards: KpiCard[] = [];

    if (this.canViewRevenue()) {
      cards.push({
        label: "Chiffre d'affaires",
        value: stats?.total_revenue_today ?? null,
        unit: 'FCFA',
        detail: "aujourd'hui",
        icon: 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
      });
    }

    cards.push(
      {
        label: 'Ventes du jour',
        value: stats?.sales_count_today ?? null,
        detail: "transactions aujourd'hui",
        icon: 'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z',
      },
      {
        label: 'Produits en stock',
        value: this.catalogTotal(),
        detail: 'références au catalogue',
        icon: 'm21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
      },
      {
        label: 'Alertes de stock bas',
        value: stats?.low_stock_count ?? null,
        detail: "sous le seuil d'alerte",
        icon: 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z',
        valueClass: 'text-red-400',
        iconClass: 'bg-red-500/15 text-red-400 ring-red-500/25',
      },
    );

    return cards;
  });

  /** Nombre de ventes du jour, 0 tant que les statistiques ne sont pas chargees. */
  protected readonly todaySalesCount = computed(() => this.statsValue()?.sales_count_today ?? 0);

  /** Nombre de produits sous le seuil d'alerte, `null` avant le chargement. */
  protected readonly lowStockCount = computed(() => this.statsValue()?.low_stock_count ?? null);

  /** Dernieres ventes renvoyees par l'API. */
  protected readonly recentSales = computed(() => this.statsValue()?.recent_sales ?? []);

  /** Serie du graphique, avec la hauteur de chaque barre en pourcentage. */
  protected readonly salesChart = computed<SalesBar[]>(() => {
    const points = this.statsValue()?.sales_chart ?? [];
    const max = Math.max(...points.map((point) => point.total_amount), 0);

    return points.map((point) => ({
      date: point.date,
      label: point.label,
      amount: point.total_amount,
      height: max > 0 ? Math.round((point.total_amount / max) * 100) : 0,
      compactAmount:
        point.total_amount >= 1000
          ? `${Math.round(point.total_amount / 1000)} k`
          : `${point.total_amount}`,
    }));
  });

  /** Libelle d'un statut de reglement. */
  protected statusLabel(status: PaymentStatus): string {
    return STATUS_LABELS[status] ?? status;
  }

  /** Couleurs du badge d'un statut de reglement. */
  protected statusClasses(status: PaymentStatus): string {
    return STATUS_CLASSES[status] ?? STATUS_CLASSES['credit'];
  }

  /** Relance le chargement des statistiques. */
  protected reloadStats(): void {
    this.stats.reload();
  }

  /** Relance le chargement des alertes de stock. */
  protected reloadLowStock(): void {
    this.lowStockProducts.reload();
  }
}
