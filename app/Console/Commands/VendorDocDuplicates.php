<?php

namespace App\Console\Commands;

use App\Models\VendorDoc;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Vendor documents that look entered twice or wrongly, for a person to
 * review. One certificate backs a GL row and a WC row, and many carriers
 * keep a policy number across renewals, so neither of those is a finding.
 * These are:
 *
 *   identical   same vendor, type, policy number, effective and expiration
 *   overlap     same vendor, type and policy number, terms overlapping but
 *               not identical (the same policy entered with other dates)
 *   copied      a GL and a WC row from the same certificate carrying the
 *               same policy number (one number copied onto the other row)
 *   odd-term    an insurance policy with a term under 180 days or over 3
 *               years (licences are left alone)
 *
 * Reports only; nothing is changed.
 */
class VendorDocDuplicates extends Command
{
    public const INSURANCE_TYPES = ['general', 'workers', 'professional', 'umbrella', 'auto'];

    protected $signature = 'vendor-docs:duplicates
        {--belongs-to-vendor-id= : One tenant\'s docs only}
        {--vendor= : One vendor id only}';

    protected $description = 'List vendor documents that look entered twice or with wrong dates';

    public function handle(): int
    {
        $docs = VendorDoc::withoutGlobalScopes()
            ->with(['vendor' => fn ($q) => $q->withoutGlobalScopes()])
            ->when($this->option('belongs-to-vendor-id'), fn ($q, $id) => $q->where('belongs_to_vendor_id', $id))
            ->when($this->option('vendor'), fn ($q, $id) => $q->where('vendor_id', $id))
            ->whereNotNull('vendor_id')
            ->get();

        $findings = self::findings($docs);
        if ($findings->isEmpty()) {
            $this->info('No suspect vendor documents.');

            return self::SUCCESS;
        }

        foreach ($findings->groupBy('vendor') as $vendor => $rows) {
            $this->newLine();
            $this->line("<options=bold>{$vendor}</>");
            foreach ($rows as $row) {
                $this->line("  [{$row['kind']}] {$row['note']}");
                foreach ($row['docs'] as $d) {
                    $this->line("      doc {$d['id']}  {$d['type']}  {$d['number']}  {$d['term']}  {$d['file']}  added {$d['added']}");
                }
            }
        }
        $this->newLine();
        $this->info(sprintf('%d finding(s): %s.', $findings->count(), $findings->countBy('kind')->map(fn ($n, $k) => "{$n} {$k}")->implode(', ')));

        return self::SUCCESS;
    }

    /**
     * Split one vendor's docs of one type into groups that carry the same
     * policy number, where "same" allows a dropped prefix or spacing.
     *
     * @param  Collection<int, VendorDoc>  $docs
     * @return Collection<int, Collection<int, VendorDoc>>
     */
    public static function samePolicyGroups(Collection $docs, \Closure $norm): Collection
    {
        $same = function (string $a, string $b): bool {
            if ($a === $b) {
                return true;
            }

            return strlen($a) >= 6 && strlen($b) >= 6 && (str_contains($a, $b) || str_contains($b, $a));
        };
        $groups = [];
        foreach ($docs as $doc) {
            $n = $norm($doc->number);
            $placed = false;
            foreach ($groups as $i => $group) {
                foreach ($group as $member) {
                    if ($same($n, $norm($member->number))) {
                        $groups[$i][] = $doc;
                        $placed = true;
                        break 2;
                    }
                }
            }
            if (! $placed) {
                $groups[] = [$doc];
            }
        }

        return collect($groups)->map(fn ($g) => collect($g));
    }

    /**
     * @param  Collection<int, VendorDoc>  $docs
     * @return Collection<int, array{kind: string, vendor: string, note: string, docs: list<array<string, string>>}>
     */
    public static function findings(Collection $docs): Collection
    {
        $norm = fn ($v) => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $v));
        $type = fn (VendorDoc $d) => strtolower((string) ($d->getRawOriginal('type') ?? $d->type));
        $describe = fn (VendorDoc $d) => [
            'id' => (string) $d->id,
            'type' => $type($d),
            'number' => (string) $d->number,
            'term' => ($d->effective_date?->format('m/d/Y') ?? '?').'–'.($d->expiration_date?->format('m/d/Y') ?? '?'),
            'file' => (string) $d->doc_filename,
            'added' => $d->created_at?->format('m/d/Y') ?? '?',
        ];
        $vendorName = fn (VendorDoc $d) => (string) ($d->vendor?->business_name ?? "vendor {$d->vendor_id}");
        $findings = collect();

        // identical / overlap: same vendor, type and policy number. Numbers
        // are the same policy when one contains the other (letters and digits
        // only, six or more): a certificate drops a prefix or a space —
        // "TARIL 11646-16" / "TARIL11646-16", "AFWCP100134391" / "100134391".
        $docs->filter(fn ($d) => strlen($norm($d->number)) >= 4 && $d->effective_date && $d->expiration_date)
            ->groupBy(fn ($d) => $d->vendor_id.'|'.$type($d))
            ->flatMap(fn (Collection $vendorType) => self::samePolicyGroups($vendorType, $norm))
            ->filter(fn ($g) => $g->count() > 1)
            ->each(function (Collection $g) use (&$findings, $describe, $vendorName) {
                $sorted = $g->sortBy('effective_date')->values();
                for ($i = 0; $i < $sorted->count(); $i++) {
                    for ($j = $i + 1; $j < $sorted->count(); $j++) {
                        $a = $sorted[$i];
                        $b = $sorted[$j];
                        if ($a->effective_date->eq($b->effective_date) && $a->expiration_date->eq($b->expiration_date)) {
                            $findings->push(['kind' => 'identical', 'vendor' => $vendorName($a), 'note' => "{$a->number}: the same term entered twice", 'docs' => [$describe($a), $describe($b)]]);
                        } elseif ($b->effective_date->lt($a->expiration_date) && $a->effective_date->lt($b->expiration_date)) {
                            $findings->push(['kind' => 'overlap', 'vendor' => $vendorName($a), 'note' => "{$a->number}: the same policy with overlapping, different terms", 'docs' => [$describe($a), $describe($b)]]);
                        }
                    }
                }
            });

        // copied: one certificate, two types, one number
        $docs->filter(fn ($d) => filled($d->doc_filename) && strlen($norm($d->number)) >= 4)
            ->groupBy(fn ($d) => $d->vendor_id.'|'.$d->doc_filename)
            ->each(function (Collection $g) use (&$findings, $describe, $vendorName, $norm, $type) {
                $byNumber = $g->groupBy(fn ($d) => $norm($d->number))->filter(fn ($same) => $same->pluck('type')->map(fn ($t) => strtolower((string) $t))->unique()->count() > 1);
                foreach ($byNumber as $same) {
                    $first = $same->first();
                    $findings->push(['kind' => 'copied', 'vendor' => $vendorName($first), 'note' => "{$first->number} on both the ".$same->map(fn ($d) => $type($d))->unique()->implode(' and ').' rows of one certificate', 'docs' => $same->map($describe)->values()->all()]);
                }
            });

        // odd-term: insurance policies run a year or two; licences run for years
        foreach ($docs as $d) {
            if (! $d->effective_date || ! $d->expiration_date || ! in_array($type($d), self::INSURANCE_TYPES, true)) {
                continue;
            }
            $days = $d->effective_date->diffInDays($d->expiration_date, false);
            if ($days < 180 || $days > 3 * 366) {
                $findings->push(['kind' => 'odd-term', 'vendor' => $vendorName($d), 'note' => "{$d->number}: a {$days}-day term", 'docs' => [$describe($d)]]);
            }
        }

        return $findings->values();
    }
}
