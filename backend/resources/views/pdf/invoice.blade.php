@php
    /** Montant formaté en français, avec le suffixe FCFA. */
    $money = static fn (float|int|string $amount): string => number_format((float) $amount, 2, ',', ' ').' FCFA';

    /** Libellés des modes de paiement enregistrés par l'API. */
    $methodLabels = [
        'cash' => 'Espèces',
        'wave' => 'Wave',
        'orange_money' => 'Orange Money',
        'bank_transfer' => 'Virement bancaire',
        'check' => 'Chèque',
    ];

    $statusLabels = [
        'paid' => 'Payée',
        'partial' => 'Partiellement payée',
        'credit' => 'À crédit',
    ];

    $statusLabel = $statusLabels[$sale->payment_status] ?? $sale->payment_status;
    $customerName = $sale->customer?->name ?? 'Client de passage';
    $paidAmount = $sale->paidAmount();
    $balanceDue = $sale->balanceDue();
@endphp

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Facture {{ $sale->reference }}</title>
    <style>
        @page {
            margin: 34px 38px 44px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            background: #ffffff;
            color: #111111;
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            line-height: 1.45;
        }

        p {
            margin: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        /* En-tête */
        .header {
            border-bottom: 2px solid #111111;
            padding-bottom: 16px;
            margin-bottom: 24px;
        }

        .header-shop {
            vertical-align: bottom;
        }

        .shop-name {
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 0.4px;
        }

        .header-doc {
            vertical-align: bottom;
            text-align: right;
        }

        .doc-title {
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .doc-reference {
            margin-top: 4px;
            font-size: 15px;
            font-weight: bold;
        }

        /* Informations de la vente */
        .meta {
            margin-bottom: 24px;
        }

        .meta-table td {
            width: 33.33%;
            vertical-align: top;
            padding: 0 16px 0 0;
        }

        .meta-table td:last-child {
            padding-right: 0;
        }

        .meta-label {
            font-size: 8px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #6b7280;
        }

        .meta-value {
            margin-top: 3px;
            font-size: 12px;
            font-weight: bold;
        }

        .meta-sub {
            margin-top: 2px;
            font-size: 10px;
            color: #4b5563;
        }

        /* Tableau des articles */
        .items {
            border: 1px solid #d4d4d8;
        }

        .items thead th {
            background: #f4f4f5;
            border-bottom: 1px solid #111111;
            padding: 9px 10px;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            text-align: left;
        }

        .items tbody td {
            border-bottom: 1px solid #e4e4e7;
            padding: 9px 10px;
            vertical-align: top;
        }

        .items tbody tr:last-child td {
            border-bottom: none;
        }

        .items tbody tr {
            page-break-inside: avoid;
        }

        .col-qty {
            width: 9%;
            text-align: center;
        }

        .col-money {
            width: 19%;
            text-align: right;
        }

        .item-name {
            font-weight: bold;
        }

        .item-sku {
            margin-top: 2px;
            font-size: 9px;
            color: #6b7280;
        }

        /* Totaux */
        .totals-wrapper {
            margin-top: 14px;
        }

        .totals-cell {
            width: 44%;
        }

        .totals td {
            padding: 5px 0;
        }

        .totals-label {
            color: #374151;
        }

        .totals-amount {
            text-align: right;
            font-weight: bold;
        }

        .total-row td {
            border-top: 2px solid #111111;
            padding-top: 9px;
            font-size: 13px;
            font-weight: bold;
        }

        .paid-row td {
            color: #047857;
        }

        .balance-row td {
            color: #b91c1c;
            font-weight: bold;
        }

        /* Paiements */
        .section-title {
            margin: 26px 0 8px;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #6b7280;
        }

        .payments {
            border: 1px solid #d4d4d8;
        }

        .payments thead th {
            background: #f4f4f5;
            border-bottom: 1px solid #111111;
            padding: 8px 10px;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            text-align: left;
        }

        .payments tbody td {
            border-bottom: 1px solid #e4e4e7;
            padding: 8px 10px;
        }

        .payments tbody tr:last-child td {
            border-bottom: none;
        }

        .empty {
            border: 1px solid #d4d4d8;
            background: #f9fafb;
            padding: 12px;
            color: #374151;
        }

        /* Pied de page */
        .footer {
            margin-top: 30px;
            border-top: 1px solid #d4d4d8;
            padding-top: 12px;
            font-size: 9px;
            color: #6b7280;
        }

        .footer-right {
            text-align: right;
        }
    </style>
</head>
<body>
    <header class="header">
        <table>
            <tr>
                <td class="header-shop">
                    @if (!empty($logoDataUri))
                        <img src="{{ $logoDataUri }}" alt="Logo" style="max-height: 48px; max-width: 130px; margin-bottom: 6px;">
                    @endif
                    <p class="shop-name">{{ $company['business_name'] ?? config('quincaillerie.name') }}</p>
                    @if (!empty($company['address'])) <p class="meta-sub">{{ $company['address'] }}</p> @endif
                    @if (!empty($company['phone'])) <p class="meta-sub">{{ $company['phone'] }}</p> @endif
                    @if (!empty($company['email'])) <p class="meta-sub">{{ $company['email'] }}</p> @endif
                </td>
                <td class="header-doc">
                    <p class="doc-title">Facture</p>
                    <p class="doc-reference">{{ $sale->reference }}</p>
                </td>
            </tr>
        </table>
    </header>

    <section class="meta">
        <table class="meta-table">
            <tr>
                <td>
                    <p class="meta-label">Client</p>
                    <p class="meta-value">{{ $customerName }}</p>
                    @if ($sale->customer?->phone)
                        <p class="meta-sub">{{ $sale->customer->phone }}</p>
                    @endif
                </td>
                <td>
                    <p class="meta-label">Date</p>
                    <p class="meta-value">{{ $sale->created_at?->format('d/m/Y') ?? '—' }}</p>
                    <p class="meta-sub">{{ $sale->created_at?->format('H:i') ?? '' }}</p>
                </td>
                <td>
                    <p class="meta-label">Vendeur</p>
                    <p class="meta-value">{{ $sale->user?->name ?? '—' }}</p>
                    <p class="meta-sub">{{ $statusLabel }}</p>
                </td>
            </tr>
        </table>
    </section>

    <table class="items">
        <thead>
            <tr>
                <th class="col-qty">Quantité</th>
                <th>Désignation</th>
                <th class="col-money">Prix unitaire</th>
                <th class="col-money">Sous-total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sale->items as $item)
                <tr>
                    <td class="col-qty">{{ $item->quantity }}</td>
                    <td>
                        <p class="item-name">{{ $item->product?->name ?? 'Produit supprimé' }}</p>
                        @if ($item->product?->sku)
                            <p class="item-sku">{{ $item->product->sku }}</p>
                        @endif
                    </td>
                    <td class="col-money">{{ $money($item->unit_price) }}</td>
                    <td class="col-money">{{ $money($item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-wrapper">
        <tr>
            <td></td>
            <td class="totals-cell">
                <table class="totals">
                    <tr class="total-row">
                        <td class="totals-label">Total</td>
                        <td class="totals-amount">{{ $money($sale->total_amount) }}</td>
                    </tr>
                    @if ($paidAmount > 0)
                        <tr class="paid-row">
                            <td class="totals-label">Payé</td>
                            <td class="totals-amount">{{ $money($paidAmount) }}</td>
                        </tr>
                    @endif
                    @if ($balanceDue > 0)
                        <tr class="balance-row">
                            <td class="totals-label">Reste à payer</td>
                            <td class="totals-amount">{{ $money($balanceDue) }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <h2 class="section-title">Modes de paiement</h2>

    @if ($sale->payments->isEmpty())
        <p class="empty">Aucun paiement enregistré — vente à crédit.</p>
    @else
        <table class="payments">
            <thead>
                <tr>
                    <th>Mode</th>
                    <th>Référence</th>
                    <th>Date</th>
                    <th class="col-money">Montant</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sale->payments as $payment)
                    <tr>
                        <td>{{ $methodLabels[$payment->method] ?? $payment->method }}</td>
                        <td>{{ $payment->reference ?: '—' }}</td>
                        <td>{{ $payment->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="col-money">{{ $money($payment->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <footer class="footer">
        <table>
            <tr>
                <td>{{ $company['invoice_footer'] ?? 'Merci de votre confiance.' }}</td>
                <td class="footer-right">
                    Document généré le {{ now()->format('d/m/Y à H:i') }}
                </td>
            </tr>
        </table>
    </footer>
</body>
</html>
