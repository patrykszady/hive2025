<?php

use App\Services\Audits\StatementCheckHighlighter;

/**
 * A two-page statement with Citibank's "Checks Paid" table on page 2: a
 * heading, "Check Date Amount" column headings four times across, rows of
 * number / date / amount (a "*" marks a gap), then the totals line. An
 * account summary on page 1 also says "Checks Paid", and is not the table.
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $rows  [number, date, amount], filled four columns across
 */
function checksPaidStatement(array $rows): string
{
    $pdf = new \FPDF('P', 'pt', 'Letter');
    $pdf->SetCompression(false);
    $pdf->SetFont('Helvetica', '', 9);

    $pdf->AddPage();
    $pdf->Text(50, 100, 'Account Summary');
    $pdf->Text(50, 120, 'Checks Paid      40,350.00');

    $pdf->AddPage();
    $pdf->Text(280, 292, 'Checks Paid');
    foreach ([50, 185, 320, 455] as $x) {
        $pdf->Text($x, 302, 'Check');
        $pdf->Text($x + 45, 302, 'Date');
        $pdf->Text($x + 85, 302, 'Amount');
    }
    foreach (array_chunk($rows, 4) as $line => $chunk) {
        foreach ($chunk as $column => [$number, $date, $amount]) {
            $x = [50, 185, 320, 455][$column];
            $pdf->Text($x + 5, 312 + $line * 10, $number);
            $pdf->Text($x + 45, 312 + $line * 10, $date);
            $pdf->Text($x + 85, 312 + $line * 10, $amount);
        }
    }
    $pdf->Text(50, 312 + (intdiv(count($rows) - 1, 4) + 2) * 10, '* indicates gap in check number sequence   Number Checks Paid: '.count($rows));

    return $pdf->Output('S');
}

it('reads every check the bank paid from the Checks Paid table, and nothing from the summary', function () {
    $pdf = checksPaidStatement([
        ['2457', '08/04', '4,500.00'], ['2459*', '08/12', '7,500.00'], ['2460', '08/25', '4,300.00'], ['2462*', '08/07', '3,000.00'],
        ['2463', '08/05', '1,960.00'], ['2464', '08/11', '4,000.00'],
    ]);

    $result = app(StatementCheckHighlighter::class)->highlight($pdf, fn () => StatementCheckHighlighter::CONFIRMED);

    expect(collect($result['checks'])->map(fn ($check) => [$check['page'], $check['number'], $check['date'], $check['amount']])->all())->toBe([
        [2, 2457, '08/04', 4500.0], [2, 2459, '08/12', 7500.0], [2, 2460, '08/25', 4300.0], [2, 2462, '08/07', 3000.0],
        [2, 2463, '08/05', 1960.0], [2, 2464, '08/11', 4000.0],
    ]);
});

it('marks a check Hive has green, one it does not yellow, and one outside the period crossed off, keeping every page', function () {
    $pdf = checksPaidStatement([['2457', '08/04', '4,500.00'], ['2463', '08/05', '1,960.00'], ['2469', '08/28', '4,590.00']]);

    $result = app(StatementCheckHighlighter::class)->highlight($pdf, fn (int $number, float $amount, string $date) => match (true) {
        $date === '08/28' => StatementCheckHighlighter::OUTSIDE,
        $number === 2457 && $amount === 4500.0 => StatementCheckHighlighter::CONFIRMED,
        default => StatementCheckHighlighter::MISSING,
    });

    $pages = (new \setasign\Fpdi\Fpdi)->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($result['pdf']));

    expect(collect($result['checks'])->mapWithKeys(fn ($check) => [$check['number'] => $check['status']])->all())
        ->toBe([2457 => 'confirmed', 2463 => 'missing', 2469 => 'outside'])
        ->and($result['pdf'])->toStartWith('%PDF')
        ->and($result['pdf'])->not->toBe($pdf)
        ->and($pages)->toBe(2);
});

it('leaves a statement without a Checks Paid table exactly as the bank sent it', function () {
    $pdf = new \FPDF('P', 'pt', 'Letter');
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Text(50, 100, 'No checks this month');
    $original = $pdf->Output('S');

    $result = app(StatementCheckHighlighter::class)->highlight($original, fn () => StatementCheckHighlighter::CONFIRMED);

    expect($result['pdf'])->toBe($original)
        ->and($result['checks'])->toBe([]);
});

it('reads the year a check was paid from the statement it is on', function () {
    $august = \Carbon\Carbon::parse('2026-08-31');
    $january = \Carbon\Carbon::parse('2026-01-30');

    expect(StatementCheckHighlighter::paidOn('08/27', $august)->toDateString())->toBe('2026-08-27')
        // A December check on a January statement was paid the year before.
        ->and(StatementCheckHighlighter::paidOn('12/30', $january)->toDateString())->toBe('2025-12-30')
        ->and(StatementCheckHighlighter::paidOn('01/02', $january)->toDateString())->toBe('2026-01-02')
        ->and(StatementCheckHighlighter::paidOn('08/27/25', $august)->toDateString())->toBe('2025-08-27')
        ->and(StatementCheckHighlighter::paidOn('13/40', $august))->toBeNull();
});
