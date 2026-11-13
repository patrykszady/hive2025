<?php

namespace App\Console\Commands;

use App\Services\Expenses\ReceiptChargePairer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PairReceiptsWithCharges extends Command
{
    protected $signature = 'expenses:pair-receipts-with-charges
        {--dry-run : List what would be paired and what is left alone, with the reason; change nothing}
        {--since= : Only receipts dated on or after this day (YYYY-MM-DD). Default: the last 12 months}
        {--all : Every receipt, however old}';

    protected $description = 'Fold a receipt that became its own expense into the expense of its bank charge when the card printed on it is the card charged and the two are each other\'s only match, and remember the printed name for the vendor (App\Services\Expenses\ReceiptChargePairer)';

    public function handle(ReceiptChargePairer $pairer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $since = match (true) {
            (bool) $this->option('all') => null,
            filled($this->option('since')) => Carbon::parse((string) $this->option('since'))->startOfDay(),
            default => now()->subMonths(12)->startOfDay(),
        };

        $outcomes = collect($pairer->run($dryRun, $since));
        $pairs = $outcomes->where('status', '!=', ReceiptChargePairer::LEFT)->values();
        $left = $outcomes->where('status', ReceiptChargePairer::LEFT)->values();

        $this->line('Receipts dated '.($since ? 'since '.$since->toDateString() : 'any day').'.');

        if ($pairs->isNotEmpty()) {
            $this->table(
                ['Receipt expense', 'Charge expense', 'Amount', 'Receipt date', 'Charge date', 'Printed name', 'Vendor', 'Why', 'Name'],
                $pairs->map(fn (array $pair): array => [
                    $pair['receipt_expense_id'],
                    $pair['charge_expense_id'],
                    $pair['amount'],
                    $pair['receipt_date'],
                    $pair['charge_date'],
                    $pair['printed_name'],
                    $pair['vendor'],
                    $pair['reason'],
                    $pair['name'] ?? '',
                ])->all(),
            );
        }

        if ($left->isNotEmpty()) {
            $this->line('Left for a person:');
            $this->table(
                ['Receipt expense', 'Charge expense', 'Amount', 'Receipt date', 'Printed name', 'Why not'],
                $left->map(fn (array $row): array => [
                    $row['receipt_expense_id'],
                    $row['charge_expense_id'] ?? '',
                    $row['amount'],
                    $row['receipt_date'],
                    $row['printed_name'],
                    $row['reason'],
                ])->all(),
            );
        }

        $this->info(match (true) {
            $pairs->isEmpty() => 'No receipt expenses to pair with a bank charge.',
            $dryRun => 'Would pair '.$pairs->count().' receipt expense(s) with their bank charge.',
            default => 'Paired '.$pairs->count().' receipt expense(s) with their bank charge.',
        });

        return self::SUCCESS;
    }
}
