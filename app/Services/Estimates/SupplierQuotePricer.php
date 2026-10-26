<?php

namespace App\Services\Estimates;

/**
 * What the client pays for each line of a supplier's quote (2026-10-26):
 * the supplier's list unit price (never its discounted price) marked up by
 * the percentage set for that supplier, plus the line's share of the tax
 * the supplier actually charged — spread by each line's share of the
 * subtotal, so the section carries exactly the tax GS pays, with no tax
 * line printed. Markup applies to the price, never to the tax. Cents are
 * rounded per unit; any rounding remainder lands on the last line so the
 * section total is exact.
 */
final class SupplierQuotePricer
{
    /**
     * @param  list<array{index: int, quantity: ?float, unit_price: ?float, amount: ?float}>  $items
     * @return array{rows: list<array{index: int, quantity: float, list_unit_price: float, markup_unit: float, tax_unit: float, client_unit_price: float, client_total: float}>, list_subtotal: float, markup_total: float, tax_total: float, client_total: float}
     */
    public static function price(array $items, float $taxAmount, float $markupPercent): array
    {
        $taxAmount = max(0.0, $taxAmount);
        $markup = max(0.0, $markupPercent) / 100;

        $lines = [];
        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 0) ?: 1.0;
            $unit = (float) ($item['unit_price'] ?? 0);
            if ($unit <= 0 && (float) ($item['amount'] ?? 0) > 0) {
                $unit = (float) $item['amount'] / $quantity;
            }
            $lines[] = ['index' => (int) $item['index'], 'quantity' => $quantity, 'unit' => $unit, 'amount' => $unit * $quantity];
        }

        $listSubtotal = array_sum(array_column($lines, 'amount'));
        $rows = [];
        $taxAssigned = 0.0;
        $count = count($lines);

        foreach ($lines as $i => $line) {
            $share = $listSubtotal > 0 ? $line['amount'] / $listSubtotal : ($count > 0 ? 1 / $count : 0);
            $lineTax = $i === $count - 1 ? round($taxAmount - $taxAssigned, 2) : round($taxAmount * $share, 2);
            $taxAssigned += $lineTax;

            $markupUnit = round($line['unit'] * $markup, 2);
            $taxUnit = round($lineTax / $line['quantity'], 2);
            $clientUnit = round($line['unit'] + $markupUnit + $taxUnit, 2);

            $rows[] = [
                'index' => $line['index'],
                'quantity' => $line['quantity'],
                'list_unit_price' => round($line['unit'], 2),
                'markup_unit' => $markupUnit,
                'tax_unit' => $taxUnit,
                'client_unit_price' => $clientUnit,
                'client_total' => round($clientUnit * $line['quantity'], 2),
            ];
        }

        return [
            'rows' => $rows,
            'list_subtotal' => round($listSubtotal, 2),
            'markup_total' => round(array_sum(array_map(fn ($r) => $r['markup_unit'] * $r['quantity'], $rows)), 2),
            'tax_total' => round($taxAmount, 2),
            'client_total' => round(array_sum(array_column($rows, 'client_total')), 2),
        ];
    }
}
