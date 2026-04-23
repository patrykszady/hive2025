<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Profit and Loss</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9pt;
            color: #333;
            padding: 20px 30px;
            line-height: 1.3;
        }

        /* ── Header Block ── */
        .report-header {
            text-align: center;
            margin-bottom: 16px;
        }
        .report-header .company-name {
            font-size: 14pt;
            font-weight: bold;
            color: #000;
        }
        .report-header .report-title {
            font-size: 11pt;
            font-weight: bold;
            color: #000;
            margin-top: 2px;
        }
        .report-header .date-range {
            font-size: 9pt;
            color: #555;
            margin-top: 2px;
        }

        /* ── Table ── */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        td {
            padding: 3px 0;
            vertical-align: top;
        }
        .col-label { width: 75%; }
        .col-amount {
            width: 25%;
            text-align: right;
            white-space: nowrap;
            padding-right: 4px;
        }

        /* ── Column Header Row ── */
        .col-header td {
            font-weight: bold;
            border-bottom: 1px solid #000;
            padding-bottom: 4px;
            margin-bottom: 4px;
        }

        /* ── Section Headers ── */
        .section-header td {
            font-weight: bold;
            padding-top: 6px;
        }

        /* ── Subheaders (subcategories with children) ── */
        .subheader td {
            font-weight: bold;
            font-style: italic;
            padding-top: 4px;
        }

        /* ── Row guide lines (light gray underline for readability) ── */
        tr:not(.section-header):not(.col-header):not(.spacer):not(.subheader) td {
            border-bottom: 1px solid #e8e8e8;
        }

        /* ── Indentation ── */
        .indent-1 .col-label { padding-left: 20px; }
        .indent-2 .col-label { padding-left: 40px; }
        .indent-3 .col-label { padding-left: 60px; }

        /* ── Subtotals ── */
        .subtotal td {
            font-weight: bold;
        }
        .subtotal .col-amount {
            border-top: 1px solid #999;
        }

        /* ── Category totals (primary expense category, COGS sub-sections) ── */
        .category-total td {
            font-weight: bold;
            padding-top: 2px;
        }
        .category-total .col-amount {
            border-top: 1px solid #000;
        }

        .section-total td {
            font-weight: bold;
            padding-top: 4px;
        }
        .section-total .col-amount {
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
        }

        .grand-total td {
            font-weight: bold;
        }
        .grand-total .col-amount {
            border-top: 1px solid #000;
            border-bottom: 3px double #000;
        }

        /* ── Spacer ── */
        .spacer td {
            padding: 6px 0;
        }

        /* ── Accrual basis footer ── */
        .report-footer {
            margin-top: 16px;
            text-align: left;
            font-size: 7.5pt;
            color: #888;
        }
    </style>
</head>
<body>
    <div class="report-header">
        <div class="company-name">{{ $companyName }}</div>
        <div class="report-title">Profit and Loss</div>
        <div class="date-range">{{ $dateRange }}</div>
    </div>

    <table>
        {{-- Column headers --}}
        <tr class="col-header">
            <td class="col-label"></td>
            <td class="col-amount">Total</td>
        </tr>

        {{-- ═══ INCOME ═══ --}}
        <tr class="section-header">
            <td class="col-label">Income</td>
            <td class="col-amount"></td>
        </tr>
        <tr class="indent-1">
            <td class="col-label">Revenue</td>
            <td class="col-amount">{{ number_format($revenue, 2) }}</td>
        </tr>
        <tr class="section-total indent-0">
            <td class="col-label"><strong>Total Income</strong></td>
            <td class="col-amount">{{ number_format($revenue, 2) }}</td>
        </tr>
        <tr class="spacer"><td colspan="2"></td></tr>

        {{-- ═══ COST OF GOODS SOLD ═══ --}}
        <tr class="section-header">
            <td class="col-label">Cost of Goods Sold</td>
            <td class="col-amount"></td>
        </tr>

        {{-- Materials --}}
        <tr class="indent-1 subheader">
            <td class="col-label">Cost of Materials</td>
            <td class="col-amount"></td>
        </tr>
        @foreach($materialsVendors as $vendorName => $vendorExpenses)
            @php $vendorSum = round((float) $vendorExpenses->sum('amount'), 2); @endphp
            @if($vendorSum == 0.0) @continue @endif
            <tr class="indent-2">
                <td class="col-label">{{ $vendorName }}</td>
                <td class="col-amount">{{ number_format($vendorSum, 2) }}</td>
            </tr>
        @endforeach
        <tr class="category-total indent-1">
            <td class="col-label">Total Cost of Materials</td>
            <td class="col-amount">{{ number_format($materialsTotal, 2) }}</td>
        </tr>
        <tr class="spacer"><td colspan="2"></td></tr>

        {{-- Labor --}}
        <tr class="indent-1 subheader">
            <td class="col-label">Cost of Labor</td>
            <td class="col-amount"></td>
        </tr>
        @foreach($laborVendors as $vendorName => $vendorChecks)
            @php $vendorSum = round((float) $vendorChecks->sum('amount'), 2); @endphp
            @if($vendorSum == 0.0) @continue @endif
            <tr class="indent-2">
                <td class="col-label">{{ $vendorName }}</td>
                <td class="col-amount">{{ number_format($vendorSum, 2) }}</td>
            </tr>
        @endforeach
        <tr class="category-total indent-1">
            <td class="col-label">Total Cost of Labor</td>
            <td class="col-amount">{{ number_format($laborTotal, 2) }}</td>
        </tr>
        <tr class="spacer"><td colspan="2"></td></tr>

        <tr class="section-total">
            <td class="col-label"><strong>Total Cost of Goods Sold</strong></td>
            <td class="col-amount">{{ number_format($cogsTotal, 2) }}</td>
        </tr>
        <tr class="spacer"><td colspan="2"></td></tr>

        {{-- ═══ GROSS PROFIT ═══ --}}
        <tr class="grand-total">
            <td class="col-label">Gross Profit</td>
            <td class="col-amount">{{ number_format($grossProfit, 2) }}</td>
        </tr>
        <tr class="spacer"><td colspan="2"></td></tr>

        {{-- ═══ EXPENSES ═══ --}}
        <tr class="section-header">
            <td class="col-label">Expenses</td>
            <td class="col-amount"></td>
        </tr>

        @foreach($expenseCategories as $categoryName => $categoryData)
            @php $categorySum = round((float) $categoryData['sum'], 2); @endphp
            @if($categorySum == 0.0) @continue @endif

            {{-- Primary Category --}}
            <tr class="indent-1 section-header">
                <td class="col-label">{{ $categoryName }}</td>
                <td class="col-amount"></td>
            </tr>

            @foreach($categoryData['subcategories'] as $subcategory)
                @php
                    $subSum = round((float) $subcategory['sum'], 2);
                    $nonZeroVendors = array_filter($subcategory['vendors'], fn($v) => round((float) $v['sum'], 2) != 0.0);
                @endphp
                @if($subSum == 0.0) @continue @endif

                @if(count($nonZeroVendors) === 1)
                    <tr class="indent-2">
                        <td class="col-label">{{ $subcategory['name'] }}</td>
                        <td class="col-amount">{{ number_format($subSum, 2) }}</td>
                    </tr>
                @else
                    <tr class="indent-2 subheader">
                        <td class="col-label">{{ $subcategory['name'] }}</td>
                        <td class="col-amount"></td>
                    </tr>
                    @foreach($subcategory['vendors'] as $vendor)
                        @php $vendSum = round((float) $vendor['sum'], 2); @endphp
                        @if($vendSum == 0.0) @continue @endif
                        <tr class="indent-3">
                            <td class="col-label">{{ $vendor['name'] }}</td>
                            <td class="col-amount">{{ number_format($vendSum, 2) }}</td>
                        </tr>
                    @endforeach
                    <tr class="subtotal indent-2">
                        <td class="col-label">Total {{ $subcategory['name'] }}</td>
                        <td class="col-amount">{{ number_format($subSum, 2) }}</td>
                    </tr>
                    <tr class="spacer"><td colspan="2"></td></tr>
                @endif
            @endforeach

            <tr class="category-total indent-1">
                <td class="col-label">Total {{ $categoryName }}</td>
                <td class="col-amount">{{ number_format($categorySum, 2) }}</td>
            </tr>
            <tr class="spacer"><td colspan="2"></td></tr>
        @endforeach

        {{-- Uncategorized transactions --}}
        @if(round((float) $uncategorizedSum, 2) != 0.0)
            <tr class="indent-1 section-header">
                <td class="col-label">Uncategorized</td>
                <td class="col-amount"></td>
            </tr>
            @foreach($uncategorizedTransactions as $txn)
                @php
                    $txnAmount = round((float) $txn->amount, 2);
                    $txnName = $txn->plaid_merchant_name
                        ?: (is_array($txn->details) ? ($txn->details['name'] ?? 'Unknown') : ($txn->details ?: 'Unknown'));
                @endphp
                @if($txnAmount == 0.0) @continue @endif
                <tr class="indent-2">
                    <td class="col-label">{{ $txnName }} <span style="color:#888;font-size:8pt;">{{ date('m/d/Y', strtotime($txn->transaction_date)) }}</span></td>
                    <td class="col-amount">{{ number_format($txnAmount, 2) }}</td>
                </tr>
            @endforeach
            <tr class="category-total indent-1">
                <td class="col-label">Total Uncategorized</td>
                <td class="col-amount">{{ number_format($uncategorizedSum, 2) }}</td>
            </tr>
            <tr class="spacer"><td colspan="2"></td></tr>
        @endif

        <tr class="section-total">
            <td class="col-label"><strong>Total Expenses</strong></td>
            <td class="col-amount">{{ number_format($expensesTotal, 2) }}</td>
        </tr>
        <tr class="spacer"><td colspan="2"></td></tr>

        {{-- ═══ NET OPERATING INCOME ═══ --}}
        <tr class="grand-total">
            <td class="col-label">Net Operating Income</td>
            <td class="col-amount">{{ number_format($netOperatingIncome, 2) }}</td>
        </tr>
        <tr class="spacer"><td colspan="2"></td></tr>

        {{-- ═══ NET INCOME ═══ --}}
        <tr class="grand-total">
            <td class="col-label">Net Income</td>
            <td class="col-amount">{{ number_format($netIncome, 2) }}</td>
        </tr>
    </table>

    <div class="report-footer">
        {{ $basisLabel }} Basis
    </div>
</body>
</html>
