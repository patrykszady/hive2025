<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Support\Transactions\MatchRuleDuplicates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes the Match Vendor rules that were saved more than once (Patryk,
 * 2026-11-13: "review the match vendor database .. it might have
 * duplicates"). Since then a save refuses a duplicate (MatchRuleDuplicates);
 * this clears the ones made before.
 *
 * A duplicate is a second rule with the same text read as a person reads it
 * (case, spacing and "\*" aside), the same vendor or code, bank, sign and
 * flags as an older one: the oldest is kept, the rest deleted — they could
 * only ever do what the oldest does. Rules giving the same text to different
 * vendors or codes are listed for a person and left alone.
 */
class DedupeVendorRules extends Command
{
    protected $signature = 'vendor-rules:dedupe {--dry-run : List the duplicates and the conflicts; change nothing}';

    protected $description = 'Delete Match Vendor rules saved more than once (the oldest of each is kept) and list rules that give one text to different vendors';

    public function handle(): int
    {
        $rules = VendorTransaction::query()->orderBy('id')->get();
        $names = Vendor::withoutGlobalScopes()->whereIn('id', $rules->pluck('vendor_id')->filter()->unique())->pluck('business_name', 'id');
        $codes = [1 => 'Deposit', 2 => 'Check', 3 => 'Transfer', 4 => 'Cash'];
        $target = fn (VendorTransaction $rule): string => $rule->vendor_id
            ? ($names[$rule->vendor_id] ?? 'vendor #'.$rule->vendor_id)
            : ($codes[(int) $rule->deposit_check] ?? 'code '.$rule->deposit_check);
        $scope = fn (VendorTransaction $rule): string => ($rule->plaid_inst_id ? ' (bank '.$rule->plaid_inst_id.')' : '').($rule->amount_sign ? ' (sign '.$rule->amount_sign.')' : '');

        $groups = $rules->groupBy(fn (VendorTransaction $rule): string => implode('|', [
            MatchRuleDuplicates::normalize((string) $rule->desc), $rule->vendor_id, $rule->deposit_check,
            $rule->plaid_inst_id, $rule->amount_sign, (string) json_decode((string) $rule->options),
        ]))->filter(fn ($group) => $group->count() > 1);

        $extra = $groups->flatMap(fn ($group) => $group->slice(1))->values();

        $this->line($groups->isEmpty() ? 'No rule is saved more than once.' : 'Saved more than once (the first id is kept):');
        foreach ($groups as $group) {
            $this->line(sprintf('  "%s" → %s%s: #%s', $group->first()->desc, $target($group->first()), $scope($group->first()), $group->pluck('id')->implode(', #')));
        }

        $conflicts = $rules->groupBy(fn (VendorTransaction $rule): string => MatchRuleDuplicates::normalize((string) $rule->desc).'|'.$rule->plaid_inst_id.'|'.$rule->amount_sign)
            ->filter(fn ($group) => $group->map(fn (VendorTransaction $rule): string => $rule->vendor_id ? 'v'.$rule->vendor_id : 'c'.$rule->deposit_check)->unique()->count() > 1);

        if ($conflicts->isNotEmpty()) {
            $this->line('One text, different vendors — for a person, left as they are:');
            foreach ($conflicts as $group) {
                $this->line('  "'.$group->first()->desc.'"'.$scope($group->first()).': '.$group->map(fn (VendorTransaction $rule): string => '#'.$rule->id.' '.$target($rule))->implode(' | '));
            }
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: would delete '.$extra->count().' duplicate rule(s).');

            return self::SUCCESS;
        }

        DB::transaction(fn () => $extra->each(fn (VendorTransaction $rule) => $rule->delete()));

        $this->info('Deleted '.$extra->count().' duplicate rule(s).');

        return self::SUCCESS;
    }
}
