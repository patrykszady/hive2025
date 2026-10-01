<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Transaction;
use App\Scopes\TransactionScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves client payments onto the deposits that actually brought them in, for
 * links the automatic matcher got wrong (add_payments_to_transaction). First
 * used on 2026-10-01: two $4,000 payments from September 2025 sat on each
 * other's deposits, and Bates' $6,000 and Harvey's $4,000 had been summed into
 * a $10,000 Citibank ATM deposit while PSFCU's feed was down.
 *
 * All links are checked before any is written, and they are written together
 * or not at all. Re-running is harmless: a payment already on its deposit is
 * reported and left alone.
 */
class RelinkPayments extends Command
{
    protected $signature = 'payments:relink
        {links* : payment_id:transaction_id pairs, e.g. 3765:29849}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Move client payments to the bank deposits they belong to';

    public function handle(): int
    {
        $pairs = [];

        foreach ($this->argument('links') as $link) {
            if (! preg_match('/^(\d+):(\d+)$/', $link, $m)) {
                $this->error("\"{$link}\" is not payment_id:transaction_id. Nothing changed.");

                return self::FAILURE;
            }

            $pairs[(int) $m[1]] = (int) $m[2];
        }

        $payments = Payment::withoutGlobalScopes()->whereIn('id', array_keys($pairs))->get()->keyBy('id');
        $deposits = Transaction::withoutGlobalScope(TransactionScope::class)->with('bank_account.bank')->whereIn('id', array_values($pairs))->get()->keyBy('id');

        foreach ($pairs as $paymentId => $transactionId) {
            $problem = $this->problemWith($payments->get($paymentId), $deposits->get($transactionId), $paymentId, $transactionId);

            if ($problem !== null) {
                $this->error($problem.' Nothing changed.');

                return self::FAILURE;
            }
        }

        $touched = collect();

        foreach ($pairs as $paymentId => $transactionId) {
            $payment = $payments[$paymentId];
            $label = sprintf('Payment %d ($%s, %s, ref %s)', $payment->id, number_format((float) $payment->amount, 2), $payment->date->toDateString(), $payment->reference ?? '—');

            if ((int) $payment->transaction_id === $transactionId) {
                $this->line("{$label}: already on transaction {$transactionId}.");

                continue;
            }

            $this->line(sprintf('%s: transaction %s → %d%s', $label, $payment->transaction_id ?? 'none', $transactionId, $this->option('dry-run') ? ' (dry run)' : ''));
            $touched->push($payment->transaction_id, $transactionId);
        }

        if ($this->option('dry-run') || $touched->isEmpty()) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($pairs, $payments) {
            foreach ($pairs as $paymentId => $transactionId) {
                $payments[$paymentId]->update(['transaction_id' => $transactionId]);
            }
        });

        // So Searchable re-sends each deposit to Scout/Typesense, and a
        // summary of what each one now holds.
        Transaction::withoutGlobalScope(TransactionScope::class)->whereIn('id', $touched->filter()->unique())->get()->each(function (Transaction $deposit) {
            $deposit->save();

            $held = (float) Payment::withoutGlobalScopes()->where('transaction_id', $deposit->id)->sum('amount');
            $this->line(sprintf(
                'Transaction %d ($%s on %s) now holds $%s in payments.',
                $deposit->id,
                number_format(abs((float) $deposit->amount), 2),
                $deposit->transaction_date->toDateString(),
                number_format($held, 2),
            ));
        });

        return self::SUCCESS;
    }

    /** Why this payment may not move to this transaction, or null when it may. */
    protected function problemWith(?Payment $payment, ?Transaction $deposit, int $paymentId, int $transactionId): ?string
    {
        if (! $payment) {
            return "Payment {$paymentId} not found.";
        }

        if (! $deposit) {
            return "Transaction {$transactionId} not found (or deleted).";
        }

        if ((float) $deposit->amount >= 0 && ! $deposit->deposit) {
            return "Transaction {$transactionId} is money out, not a deposit.";
        }

        if ((int) $deposit->bank_account?->bank?->vendor_id !== (int) $payment->belongs_to_vendor_id) {
            return "Transaction {$transactionId} is another company's bank account than payment {$paymentId}.";
        }

        return null;
    }
}
