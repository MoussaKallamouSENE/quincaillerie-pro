import { describe, expect, it } from 'vitest';

import {
  REPORT_COLUMNS,
  ReportColumn,
  fieldValue,
  formatCell,
  formatDate,
  formatDateTime,
  formatMoney,
  formatNumber,
} from './report-columns';
import { REPORT_TYPES, ReportRow, ReportType } from '../../services/report-service';

const ROWS: Record<ReportType, ReportRow> = {
  sales: {
    reference: 'VTE-001',
    created_at: '2026-11-03T14:30:00Z',
    customer: { name: 'Awa Traoré' },
    user: { name: 'Moussa Diop' },
    items_count: 2,
    payment_status: 'paid',
    total_amount: 150000,
  },
  purchases: {
    reference: 'ACH-001',
    created_at: '2026-11-02T09:00:00Z',
    supplier: { name: 'Cimentier SA' },
    status: 'received',
    received_at: '2026-11-03T10:00:00Z',
    items_count: 10,
    total_amount: 500000,
  },
  stock: {
    name: 'Ciment',
    sku: 'CIM-001',
    category: { name: 'Matériaux' },
    unit: { name: 'Sac' },
    stock_quantity: 5,
    min_stock_alert: 2,
    purchase_price: 1000,
    sale_price: 1500,
  },
  out_of_stock: {
    name: 'Peinture',
    sku: 'PEI-001',
    category: { name: 'Finition' },
    unit: { name: 'Pot' },
    stock_quantity: 0,
    min_stock_alert: 3,
  },
  low_stock: {
    name: 'Vis',
    sku: 'VIS-001',
    category: { name: 'Quincaillerie' },
    stock_quantity: 1,
    min_stock_alert: 5,
  },
  stock_movements: {
    created_at: '2026-11-03T11:00:00Z',
    product: { name: 'Ciment' },
    type: 'in',
    quantity: 3,
    reference: 'BON-001',
    user: { name: 'Moussa Diop' },
    note: 'Réception',
  },
  customers: {
    name: 'Awa Traoré',
    phone: '77 000 00 00',
    email: 'awa@example.com',
    address: 'Dakar',
    outstanding_balance: 25000,
    credit_limit: 100000,
    is_active: false,
  },
  suppliers: {
    name: 'Cimentier SA',
    contact_person: 'M. Fall',
    phone: '33 000 00 00',
    email: 'contact@cimentier.test',
    address: 'Rufisque',
    is_active: true,
  },
  cash_sessions: {
    opened_at: '2026-11-03T08:00:00Z',
    closed_at: '2026-11-03T18:00:00Z',
    user: { name: 'Moussa Diop' },
    status: 'closed',
    opening_balance: 10000,
    closing_balance: 10500,
    expected_cash: 10000,
  },
  cash_movements: {
    created_at: '2026-11-03T12:00:00Z',
    cash_session: { opened_at: '2026-11-03T08:00:00Z' },
    user: { name: 'Moussa Diop' },
    type: 'out',
    reason: 'Achat fournitures',
    amount: 2500,
  },
  expenses: {
    date: '2026-11-03',
    name: 'Carburant',
    user: { name: 'Moussa Diop' },
    cash_session: { opened_at: '2026-11-03T08:00:00Z' },
    amount: 10000,
  },
};

function column(type: ReportType, key: string): ReportColumn {
  const match = REPORT_COLUMNS[type].find((candidate) => candidate.key === key);

  if (!match) {
    throw new Error(`Colonne introuvable : ${type}.${key}`);
  }

  return match;
}

describe('report columns', () => {
  it('expose une définition pour les onze rapports', () => {
    expect(Object.keys(REPORT_COLUMNS).sort()).toEqual(
      REPORT_TYPES.map((type) => type.id).sort(),
    );

    for (const type of REPORT_TYPES) {
      expect(REPORT_COLUMNS[type.id].length).toBeGreaterThan(0);
      expect(REPORT_COLUMNS[type.id].every((item) => item.label.length > 0)).toBe(true);
    }
  });

  it('lit les relations imbriquées et retourne null pour une valeur absente', () => {
    expect(fieldValue(ROWS.sales, 'customer.name')).toBe('Awa Traoré');
    expect(fieldValue(ROWS.cash_movements, 'cash_session.opened_at')).toBe(
      '2026-11-03T08:00:00Z',
    );
    expect(fieldValue(ROWS.sales, 'customer.phone')).toBeNull();
    expect(fieldValue({}, 'customer.name')).toBeNull();
  });

  it('formate les nombres et les montants en FCFA', () => {
    expect(formatNumber(1234)).toContain('1');
    expect(formatNumber(null)).toBe('—');
    expect(formatMoney(150000)).toContain('150');
    expect(formatMoney(150000)).toContain('FCFA');
    expect(formatMoney(undefined)).toBe('—');
  });

  it('formate les dates sans décalage de fuseau pour les dates seules', () => {
    expect(formatDate('2026-11-03')).toBe('03/11/2026');
    expect(formatDateTime('2026-11-03')).toBe('03/11/2026');
    expect(formatDateTime('2026-11-03T14:30:00Z')).toMatch(/2026/);
    expect(formatDate(null)).toBe('—');
  });

  it('remplace les relations par leur nom et les statuts par un libellé', () => {
    expect(formatCell(ROWS.sales, column('sales', 'customer'))).toBe('Awa Traoré');
    expect(formatCell(ROWS.sales, column('sales', 'user'))).toBe('Moussa Diop');
    expect(formatCell(ROWS.sales, column('sales', 'payment_status'))).toBe('Payée');
    expect(formatCell(ROWS.purchases, column('purchases', 'supplier'))).toBe(
      'Cimentier SA',
    );
    expect(formatCell(ROWS.purchases, column('purchases', 'status'))).toBe(
      'Réceptionnée',
    );
    expect(formatCell(ROWS.stock_movements, column('stock_movements', 'product'))).toBe(
      'Ciment',
    );
    expect(formatCell(ROWS.stock_movements, column('stock_movements', 'type'))).toBe(
      'Entrée',
    );
    expect(formatCell(ROWS.cash_sessions, column('cash_sessions', 'status'))).toBe(
      'Clôturée',
    );
    expect(formatCell(ROWS.cash_movements, column('cash_movements', 'type'))).toBe(
      'Sortie',
    );
    expect(formatCell(ROWS.customers, column('customers', 'is_active'))).toBe('Inactif');
    expect(formatCell(ROWS.suppliers, column('suppliers', 'is_active'))).toBe('Actif');
  });

  it('calcule la valeur du stock, le manquant et l’écart de caisse', () => {
    expect(formatCell(ROWS.stock, column('stock', 'stock_value'))).toContain('FCFA');
    expect(formatCell(ROWS.low_stock, column('low_stock', 'missing_quantity'))).toBe('4');
    expect(formatCell(ROWS.cash_sessions, column('cash_sessions', 'difference'))).toContain(
      '500',
    );
    expect(
      formatCell(ROWS.stock_movements, column('stock_movements', 'quantity')),
    ).toBe('+3');
  });

  it('affiche un tiret pour les valeurs absentes et aucun objet brut', () => {
    for (const type of REPORT_TYPES) {
      for (const item of REPORT_COLUMNS[type.id]) {
        const value = formatCell({}, item);

        expect(value.length).toBeGreaterThan(0);
        expect(value).not.toContain('[object');
      }
    }

    expect(formatCell({}, column('sales', 'reference'))).toBe('—');
    expect(formatCell(ROWS.expenses, column('expenses', 'cash_session.opened_at'))).toMatch(
      /2026/,
    );
  });
});
