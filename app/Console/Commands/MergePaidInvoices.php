<?php

namespace App\Console\Commands;

use App\Services\Expenses\PaidInvoiceMerger;
use Illuminate\Console\Command;

class MergePaidInvoices extends Command
{
    protected $signature = 'expenses:merge-paid-invoices
        {--dry-run : List the invoice expenses that would fold into their payments, change nothing}';

    protected $description = 'Fold invoices entered as their own expense into the expense that paid them (App\Services\Expenses\PaidInvoiceMerger)';

    public function handle(PaidInvoiceMerger $merger): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $merges = $merger->run($dryRun);

        if ($merges === []) {
            $this->info('No invoice expenses to fold into their payments.');

            return self::SUCCESS;
        }

        $this->table(
            ['Invoice expense', 'Paid by expense', 'Invoice', 'Amount', 'Rule'],
            array_map(fn (array $merge) => [$merge['invoice_expense_id'], $merge['payment_expense_id'], $merge['invoice'], $merge['amount'], $merge['rule']], $merges),
        );

        $this->info(($dryRun ? 'Would fold ' : 'Folded ').count($merges).' invoice expense(s) into their payments.');

        return self::SUCCESS;
    }
}
