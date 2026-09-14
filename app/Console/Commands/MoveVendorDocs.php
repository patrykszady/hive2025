<?php

namespace App\Console\Commands;

use App\Jobs\LookupEwccvForVendor;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Console\Command;

/**
 * Re-file documents matched to the wrong vendor — typically a certificate
 * that landed on a duplicate record of the same business. The workers-comp
 * tracking stamp is cleared so the state lookup runs again under the right
 * vendor.
 */
class MoveVendorDocs extends Command
{
    protected $signature = 'vendor-docs:move
        {--doc=* : Vendor doc id(s) to move}
        {--to-vendor= : Vendor id to file them under}
        {--apply : Write the changes (without this it only reports what it would do)}';

    protected $description = 'Re-file vendor documents (COIs, licences) under another vendor.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('doc'))));
        $toVendor = Vendor::withoutGlobalScopes()->find((int) $this->option('to-vendor'));

        if ($ids === [] || ! $toVendor) {
            $this->error('Give at least one --doc=ID and a --to-vendor=ID that exists.');

            return self::FAILURE;
        }

        $docs = VendorDoc::withoutGlobalScopes()->whereIn('id', $ids)->with('vendor')->orderBy('id')->get();

        if ($docs->count() !== count($ids)) {
            $this->error('Not found: '.implode(', ', array_diff($ids, $docs->pluck('id')->all())));

            return self::FAILURE;
        }

        $this->line($apply
            ? "Moving {$docs->count()} document(s) to vendor {$toVendor->id} ({$toVendor->name})."
            : "DRY RUN — {$docs->count()} document(s) would move to vendor {$toVendor->id} ({$toVendor->name}). Re-run with --apply to write.");
        $this->newLine();

        foreach ($docs as $doc) {
            $notes = [];

            if ((int) $doc->vendor_id === (int) $toVendor->id) {
                $notes[] = 'already there';
            }

            // A vendor change alone does not re-queue the state lookup (the
            // observer watches type, number and expiry), so queue it here —
            // the policy now has to be verified under the right vendor.
            $requeue = $doc->type === 'workers'
                && (! $doc->expiration_date || ! $doc->expiration_date->isPast())
                && (int) $doc->vendor_id !== (int) $toVendor->id;

            if (isset($doc->options['ewccv'])) {
                $notes[] = 'workers-comp tracking stamp cleared';
            }

            if ($requeue) {
                $notes[] = 'workers-comp lookup queued';
            }

            $this->line(sprintf(
                '  doc %-5s %-8s #%-18s vendor %s (%s) → %s%s',
                $doc->id,
                $doc->type,
                (string) $doc->number,
                $doc->vendor_id,
                $doc->vendor?->name ?? '—',
                $toVendor->id,
                $notes === [] ? '' : ' | '.implode(' | ', $notes),
            ));

            if (! $apply) {
                continue;
            }

            $options = $doc->options ?? [];
            unset($options['ewccv']);

            $doc->vendor_id = $toVendor->id;
            $doc->options = $options === [] ? null : $options;
            $doc->saveQuietly();

            if ($requeue) {
                LookupEwccvForVendor::dispatch($doc->id)->afterCommit();
            }
        }

        $this->newLine();
        $this->info($apply ? 'Done.' : 'Nothing was written. Re-run with --apply.');

        return self::SUCCESS;
    }
}
