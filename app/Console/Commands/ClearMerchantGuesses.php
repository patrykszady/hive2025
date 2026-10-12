<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * Drop the merchant name Plaid only guessed on a payment-app transfer — a
 * Venmo/Zelle/PayPal debit whose counterparties name no merchant (see
 * Transaction::merchantNameFromPlaid()). 29182 ($200 Venmo, 2026-07-12) wore
 * "Anthropic Headquarters" on every screen and in the check matcher's payee
 * grouping. The sync no longer stores such a guess; this cleans the rows
 * that already carry one. Dry-run by default, re-runnable.
 */
class ClearMerchantGuesses extends Command
{
    protected $signature = 'transactions:clear-merchant-guesses
        {--apply : Actually clear them (default is a dry-run report)}';

    protected $description = "Clear a Plaid merchant name that is only a guess on a payment-app transfer (Venmo, Zelle, PayPal …)";

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $guesses = Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('plaid_merchant_name')
            ->where('plaid_merchant_name', '!=', '')
            ->where('details', 'like', '%payment_app%')
            ->get()
            ->filter(fn (Transaction $transaction) => $transaction->merchantNameIsPaymentAppGuess())
            ->values();

        if ($guesses->isEmpty()) {
            $this->info('No merchant guesses on payment-app transfers.');

            return self::SUCCESS;
        }

        foreach ($guesses as $transaction) {
            $this->line(sprintf('  %d  %s  $%s  [%s] -> none  (%s)', $transaction->id, $transaction->transaction_date?->toDateString(), number_format((float) $transaction->amount, 2), $transaction->plaid_merchant_name, $transaction->plaid_merchant_description));
        }

        if (! $apply) {
            $this->warn(sprintf('Dry-run only: %d row(s) would lose a guessed merchant name. Re-run with --apply.', $guesses->count()));

            return self::SUCCESS;
        }

        foreach ($guesses as $transaction) {
            $transaction->plaid_merchant_name = null;
            $transaction->save();
        }

        Transaction::withoutGlobalScopes()->whereIn('id', $guesses->pluck('id'))->searchable();

        $this->info(sprintf('Cleared the guessed merchant name on %d row(s) and reindexed them.', $guesses->count()));

        return self::SUCCESS;
    }
}
