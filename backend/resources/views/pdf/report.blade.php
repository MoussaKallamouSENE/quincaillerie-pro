@php
    $brand = $company['business_name'] ?? config('quincaillerie.name', 'Quincaillerie Pro');
    $periodLabel = 'du '.date('d/m/Y', strtotime($from)).' au '.date('d/m/Y', strtotime($to));
    $lineLabel = number_format($totalCount, 0, ',', ' ').' ligne'.($totalCount > 1 ? 's' : '');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $label }} — {{ $brand }}</title>
    <style>
        @page {
            margin: 20mm 12mm 20mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            background: #ffffff;
            color: #1e293b;
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 9px;
            line-height: 1.4;
        }

        p {
            margin: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .header {
            border-bottom: 2px solid #047857;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }

        .header-shop {
            vertical-align: bottom;
            width: 60%;
        }

        .header-document {
            vertical-align: bottom;
            width: 40%;
            text-align: right;
        }

        .brand {
            font-size: 21px;
            font-weight: bold;
            color: #064e3b;
            letter-spacing: 0.2px;
        }

        .brand-subtitle {
            margin-top: 2px;
            color: #64748b;
            font-size: 8px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .company-details {
            margin-top: 5px;
            color: #475569;
            font-size: 8px;
            line-height: 1.5;
        }

        .document-kicker {
            color: #047857;
            font-size: 8px;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .document-title {
            margin-top: 4px;
            color: #0f172a;
            font-size: 19px;
            font-weight: bold;
            line-height: 1.2;
        }

        .document-period {
            margin-top: 4px;
            color: #475569;
            font-size: 9px;
        }

        .meta {
            margin-bottom: 12px;
        }

        .meta td {
            padding: 0 14px 0 0;
            vertical-align: top;
        }

        .meta td:last-child {
            padding-right: 0;
        }

        .meta-label {
            color: #64748b;
            font-size: 7px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .meta-value {
            margin-top: 2px;
            color: #0f172a;
            font-size: 9px;
            font-weight: bold;
        }

        .notice {
            margin-bottom: 10px;
            border: 1px solid #f59e0b;
            background: #fffbeb;
            padding: 7px 9px;
            color: #92400e;
            font-size: 8px;
        }

        .report-note {
            margin-top: 10px;
            border-left: 3px solid #10b981;
            background: #f0fdf4;
            padding: 7px 9px;
            color: #166534;
            font-size: 8px;
        }

        .data-table {
            border: 1px solid #cbd5e1;
        }

        .data-table thead {
            display: table-header-group;
        }

        .data-table th {
            border-bottom: 1px solid #94a3b8;
            background: #f1f5f9;
            padding: 6px 5px;
            color: #334155;
            font-size: 7px;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-align: left;
            text-transform: uppercase;
        }

        .data-table td {
            border-bottom: 1px solid #e2e8f0;
            padding: 5px;
            color: #1e293b;
            vertical-align: top;
        }

        .data-table tbody tr:nth-child(even) td {
            background: #f8fafc;
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr {
            page-break-inside: avoid;
        }

        .left {
            text-align: left;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
        }

        .empty-state {
            border: 1px dashed #94a3b8;
            background: #f8fafc;
            padding: 28px 20px;
            text-align: center;
        }

        .empty-state strong {
            display: block;
            color: #334155;
            font-size: 11px;
        }

        .empty-state span {
            display: block;
            margin-top: 4px;
            color: #64748b;
            font-size: 8px;
        }

        .totals {
            margin-top: 12px;
        }

        .totals td {
            padding: 0;
            vertical-align: top;
        }

        .totals-spacer {
            width: 52%;
        }

        .totals-box {
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            padding: 8px 10px;
        }

        .totals-row td {
            padding: 3px 0;
        }

        .totals-label {
            color: #475569;
        }

        .totals-value {
            color: #0f172a;
            font-weight: bold;
            text-align: right;
        }

        .footer {
            position: fixed;
            bottom: -13mm;
            left: 0;
            right: 0;
            border-top: 1px solid #cbd5e1;
            padding-top: 5px;
            color: #64748b;
            font-size: 7px;
        }

        .footer-left {
            width: 70%;
        }

        .footer-right {
            width: 30%;
            text-align: right;
        }

        .page-number:after {
            content: "Page " counter(page) " / " counter(pages);
        }
    </style>
</head>
<body>
    <header class="header">
        <table>
            <tr>
                <td class="header-shop">
                    @if (!empty($logoDataUri))
                        <img src="{{ $logoDataUri }}" alt="Logo" style="max-height: 44px; max-width: 120px; margin-bottom: 5px;">
                    @endif
                    <p class="brand">{{ $brand }}</p>
                    <p class="brand-subtitle">Gestion commerciale</p>
                    <div class="company-details">
                        @if (!empty($company['address'])) <p>{{ $company['address'] }}</p> @endif
                        @if (!empty($company['phone'])) <p>Tél. : {{ $company['phone'] }}</p> @endif
                        @if (!empty($company['email'])) <p>{{ $company['email'] }}</p> @endif
                    </div>
                </td>
                <td class="header-document">
                    <p class="document-kicker">Rapport d’activité</p>
                    <p class="document-title">{{ $label }}</p>
                    <p class="document-period">Période {{ $periodLabel }}</p>
                </td>
            </tr>
        </table>
    </header>

    <table class="meta">
        <tr>
            <td>
                <p class="meta-label">Période analysée</p>
                <p class="meta-value">{{ $periodLabel }}</p>
            </td>
            <td>
                <p class="meta-label">Date de génération</p>
                <p class="meta-value">{{ $generatedAt }}</p>
            </td>
            <td>
                <p class="meta-label">Volume</p>
                <p class="meta-value">{{ $lineLabel }}</p>
            </td>
            <td>
                <p class="meta-label">Devise</p>
                <p class="meta-value">FCFA (XOF)</p>
            </td>
        </tr>
    </table>

    @if ($truncated)
        <div class="notice">
            L’export est limité aux {{ number_format($limit, 0, ',', ' ') }} premières lignes sur {{ $lineLabel }}.
            Les totaux ci-dessous portent uniquement sur les lignes affichées. Affinez les filtres pour un export complet.
        </div>
    @endif

    @if (empty($rows))
        <div class="empty-state">
            <strong>Aucune donnée pour cette période</strong>
            <span>Aucune ligne ne correspond aux filtres sélectionnés. Modifiez la période ou la recherche.</span>
        </div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th class="{{ $column['align'] ?? 'left' }}">{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $index => $value)
                            <td class="{{ $columns[$index]['align'] ?? 'left' }}">{{ $value }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if (!empty($totals))
            <table class="totals">
                <tr>
                    <td class="totals-spacer"></td>
                    <td>
                        <div class="totals-box">
                            <table>
                                @foreach ($totals as $total)
                                    <tr class="totals-row">
                                        <td class="totals-label">{{ $total['label'] }}</td>
                                        <td class="totals-value">{{ $total['value'] }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </div>
                    </td>
                </tr>
            </table>
        @endif
    @endif

    @if ($note)
        <div class="report-note">{{ $note }}</div>
    @endif

    <div class="footer">
        <table>
            <tr>
                <td class="footer-left">
                    {{ $brand }} — {{ $label }} — généré le {{ $generatedAt }}
                </td>
                <td class="footer-right">
                    <span class="page-number"></span>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
