import { ReportRow, ReportType } from '../../services/report-service';

export type ReportColumnKind = 'text' | 'money' | 'date' | 'datetime' | 'number';

export interface ReportColumn {
  readonly key: string;
  readonly label: string;
  readonly kind?: ReportColumnKind;
  readonly align?: 'left' | 'right' | 'center';
  readonly value?: (row: ReportRow) => unknown;
}

const PAYMENT_STATUS_LABELS: Record<string, string> = {
  paid: 'Payée',
  partial: 'Partielle',
  credit: 'À crédit',
};

const PURCHASE_STATUS_LABELS: Record<string, string> = {
  pending: 'En attente',
  received: 'Réceptionnée',
  cancelled: 'Annulée',
};

const MOVEMENT_TYPE_LABELS: Record<string, string> = {
  in: 'Entrée',
  out: 'Sortie',
  adjustment: 'Ajustement',
};

const CASH_SESSION_STATUS_LABELS: Record<string, string> = {
  open: 'Ouverte',
  closed: 'Clôturée',
};

const CASH_MOVEMENT_TYPE_LABELS: Record<string, string> = {
  in: 'Entrée',
  out: 'Sortie',
};

/** Lit une valeur imbriquee dans une ligne (`customer.name`, `product.sku`...). */
export function fieldValue(row: ReportRow, path: string): unknown {
  let current: unknown = row;

  for (const part of path.split('.')) {
    if (current === null || typeof current !== 'object') {
      return null;
    }

    current = (current as Record<string, unknown>)[part];
  }

  return current ?? null;
}

function relationName(row: ReportRow, path: string): unknown {
  const relation = fieldValue(row, path);

  if (relation && typeof relation === 'object' && 'name' in relation) {
    const name = (relation as { name?: unknown }).name;

    return typeof name === 'string' && name !== '' ? name : null;
  }

  return typeof relation === 'string' && relation !== '' ? relation : null;
}

function toNumber(value: unknown): number | null {
  if (value === null || value === undefined || value === '') {
    return null;
  }

  const number = Number(value);

  return Number.isFinite(number) ? number : null;
}

export function formatNumber(value: unknown): string {
  const number = toNumber(value);

  if (number === null) {
    return '—';
  }

  return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(number);
}

export function formatMoney(value: unknown): string {
  const number = toNumber(value);

  if (number === null) {
    return '—';
  }

  return `${formatNumber(number)} FCFA`;
}

function parseDate(value: unknown): Date | null {
  if (value instanceof Date) {
    return Number.isNaN(value.getTime()) ? null : value;
  }

  if (typeof value !== 'string' || value === '') {
    return null;
  }

  const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

  if (dateOnly) {
    return new Date(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3]));
  }

  const date = new Date(value);

  return Number.isNaN(date.getTime()) ? null : date;
}

export function formatDate(value: unknown): string {
  const date = parseDate(value);

  if (!date) {
    return '—';
  }

  return new Intl.DateTimeFormat('fr-FR').format(date);
}

export function formatDateTime(value: unknown): string {
  if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return formatDate(value);
  }

  const date = parseDate(value);

  if (!date) {
    return '—';
  }

  return new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(date);
}

function statusLabel(labels: Record<string, string>, value: unknown): unknown {
  if (typeof value !== 'string') {
    return null;
  }

  return labels[value] ?? value;
}

function stockValue(row: ReportRow): number | null {
  const stock = toNumber(fieldValue(row, 'stock_quantity'));
  const purchasePrice = toNumber(fieldValue(row, 'purchase_price'));

  if (stock === null || purchasePrice === null) {
    return null;
  }

  return stock * purchasePrice;
}

function missingQuantity(row: ReportRow): number | null {
  const stock = toNumber(fieldValue(row, 'stock_quantity'));
  const threshold = toNumber(fieldValue(row, 'min_stock_alert'));

  if (stock === null || threshold === null) {
    return null;
  }

  return Math.max(0, threshold - stock);
}

function cashDifference(row: ReportRow): number | null {
  const closing = toNumber(fieldValue(row, 'closing_balance'));
  const expected = toNumber(fieldValue(row, 'expected_cash'));

  if (closing === null || expected === null) {
    return null;
  }

  return closing - expected;
}

function signedQuantity(row: ReportRow): string {
  const quantity = toNumber(fieldValue(row, 'quantity'));

  if (quantity === null) {
    return '—';
  }

  return quantity > 0 ? `+${formatNumber(quantity)}` : formatNumber(quantity);
}

function activeLabel(row: ReportRow): string {
  return fieldValue(row, 'is_active') === false ? 'Inactif' : 'Actif';
}

/**
 * Colonnes metier de chaque rapport.
 *
 * Aucune colonne brute de la base n'est exposee : les relations sont
 * remplacees par leur nom et les montants sont formates en FCFA.
 */
export const REPORT_COLUMNS: Record<ReportType, readonly ReportColumn[]> = {
  sales: [
    { key: 'reference', label: 'Référence' },
    { key: 'created_at', label: 'Date', kind: 'datetime' },
    {
      key: 'customer',
      label: 'Client',
      value: (row) => relationName(row, 'customer') ?? 'Client de passage',
    },
    { key: 'user', label: 'Vendeur', value: (row) => relationName(row, 'user') },
    { key: 'items_count', label: 'Articles', kind: 'number', align: 'center' },
    {
      key: 'payment_status',
      label: 'Paiement',
      value: (row) => statusLabel(PAYMENT_STATUS_LABELS, fieldValue(row, 'payment_status')),
    },
    { key: 'total_amount', label: 'Total', kind: 'money', align: 'right' },
  ],

  purchases: [
    { key: 'reference', label: 'Référence' },
    { key: 'created_at', label: 'Date de commande', kind: 'datetime' },
    { key: 'supplier', label: 'Fournisseur', value: (row) => relationName(row, 'supplier') },
    {
      key: 'status',
      label: 'Statut',
      value: (row) => statusLabel(PURCHASE_STATUS_LABELS, fieldValue(row, 'status')),
    },
    { key: 'received_at', label: 'Réceptionné le', kind: 'datetime' },
    { key: 'items_count', label: 'Articles', kind: 'number', align: 'center' },
    { key: 'total_amount', label: 'Total', kind: 'money', align: 'right' },
  ],

  stock: [
    { key: 'name', label: 'Produit' },
    { key: 'sku', label: 'SKU' },
    { key: 'category', label: 'Catégorie', value: (row) => relationName(row, 'category') },
    { key: 'unit', label: 'Unité', value: (row) => relationName(row, 'unit') },
    { key: 'stock_quantity', label: 'Stock', kind: 'number', align: 'right' },
    { key: 'min_stock_alert', label: 'Seuil', kind: 'number', align: 'right' },
    { key: 'purchase_price', label: 'Prix d’achat', kind: 'money', align: 'right' },
    { key: 'sale_price', label: 'Prix de vente', kind: 'money', align: 'right' },
    {
      key: 'stock_value',
      label: 'Valeur du stock',
      kind: 'money',
      align: 'right',
      value: (row) => stockValue(row),
    },
  ],

  out_of_stock: [
    { key: 'name', label: 'Produit' },
    { key: 'sku', label: 'SKU' },
    { key: 'category', label: 'Catégorie', value: (row) => relationName(row, 'category') },
    { key: 'unit', label: 'Unité', value: (row) => relationName(row, 'unit') },
    { key: 'stock_quantity', label: 'Stock', kind: 'number', align: 'right' },
    { key: 'min_stock_alert', label: 'Seuil', kind: 'number', align: 'right' },
  ],

  low_stock: [
    { key: 'name', label: 'Produit' },
    { key: 'sku', label: 'SKU' },
    { key: 'category', label: 'Catégorie', value: (row) => relationName(row, 'category') },
    { key: 'stock_quantity', label: 'Stock', kind: 'number', align: 'right' },
    { key: 'min_stock_alert', label: 'Seuil', kind: 'number', align: 'right' },
    {
      key: 'missing_quantity',
      label: 'Manquant',
      kind: 'number',
      align: 'right',
      value: (row) => missingQuantity(row),
    },
  ],

  stock_movements: [
    { key: 'created_at', label: 'Date', kind: 'datetime' },
    { key: 'product', label: 'Produit', value: (row) => relationName(row, 'product') },
    { key: 'product.sku', label: 'SKU' },
    {
      key: 'type',
      label: 'Type',
      value: (row) => statusLabel(MOVEMENT_TYPE_LABELS, fieldValue(row, 'type')),
    },
    {
      key: 'quantity',
      label: 'Quantité',
      kind: 'text',
      align: 'right',
      value: (row) => signedQuantity(row),
    },
    { key: 'reference', label: 'Référence' },
    { key: 'user', label: 'Utilisateur', value: (row) => relationName(row, 'user') },
    { key: 'note', label: 'Note' },
  ],

  customers: [
    { key: 'name', label: 'Client' },
    { key: 'phone', label: 'Téléphone' },
    { key: 'email', label: 'Email' },
    { key: 'address', label: 'Adresse' },
    { key: 'outstanding_balance', label: 'Encours', kind: 'money', align: 'right' },
    { key: 'credit_limit', label: 'Limite de crédit', kind: 'money', align: 'right' },
    { key: 'is_active', label: 'Statut', value: (row) => activeLabel(row) },
  ],

  suppliers: [
    { key: 'name', label: 'Fournisseur' },
    { key: 'contact_person', label: 'Contact' },
    { key: 'phone', label: 'Téléphone' },
    { key: 'email', label: 'Email' },
    { key: 'address', label: 'Adresse' },
    { key: 'is_active', label: 'Statut', value: (row) => activeLabel(row) },
  ],

  cash_sessions: [
    { key: 'opened_at', label: 'Ouverture', kind: 'datetime' },
    { key: 'closed_at', label: 'Clôture', kind: 'datetime' },
    { key: 'user', label: 'Utilisateur', value: (row) => relationName(row, 'user') },
    {
      key: 'status',
      label: 'Statut',
      value: (row) => statusLabel(CASH_SESSION_STATUS_LABELS, fieldValue(row, 'status')),
    },
    { key: 'opening_balance', label: 'Fond initial', kind: 'money', align: 'right' },
    { key: 'closing_balance', label: 'Solde compté', kind: 'money', align: 'right' },
    { key: 'expected_cash', label: 'Solde théorique', kind: 'money', align: 'right' },
    {
      key: 'difference',
      label: 'Écart',
      kind: 'money',
      align: 'right',
      value: (row) => cashDifference(row),
    },
  ],

  cash_movements: [
    { key: 'created_at', label: 'Date', kind: 'datetime' },
    { key: 'cash_session.opened_at', label: 'Session', kind: 'datetime' },
    { key: 'user', label: 'Utilisateur', value: (row) => relationName(row, 'user') },
    {
      key: 'type',
      label: 'Sens',
      value: (row) => statusLabel(CASH_MOVEMENT_TYPE_LABELS, fieldValue(row, 'type')),
    },
    { key: 'reason', label: 'Motif' },
    { key: 'amount', label: 'Montant', kind: 'money', align: 'right' },
  ],

  expenses: [
    { key: 'date', label: 'Date', kind: 'date' },
    { key: 'name', label: 'Dépense' },
    { key: 'user', label: 'Auteur', value: (row) => relationName(row, 'user') },
    {
      key: 'cash_session.opened_at',
      label: 'Session de caisse',
      kind: 'datetime',
    },
    { key: 'amount', label: 'Montant', kind: 'money', align: 'right' },
  ],
};

/** Valeur affichable d'une cellule, deja formatee pour l'utilisateur. */
export function formatCell(row: ReportRow, column: ReportColumn): string {
  const value = column.value ? column.value(row) : fieldValue(row, column.key);

  if (value === null || value === undefined || value === '') {
    return '—';
  }

  switch (column.kind) {
    case 'money':
      return formatMoney(value);
    case 'number':
      return formatNumber(value);
    case 'date':
      return formatDate(value);
    case 'datetime':
      return formatDateTime(value);
    default:
      return String(value);
  }
}
