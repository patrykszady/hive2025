<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\LeadContactProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DedupeLeadClients extends Command
{
    protected $signature = 'clients:dedupe-leads
                            {--dry-run : Preview changes without modifying records}
                            {--force : Merge groups even when user sets differ (NOT recommended)}';

    protected $description = 'Merge duplicate Client rows that share a normalized street address (typically created by repeated Lead API submissions).';

    public function handle(): int
    {
        $clients = Client::withoutGlobalScopes()
            ->with(['users:id', 'vendors:id'])
            ->whereNotNull('address')
            ->orderBy('id')
            ->get();

        $groups = $clients
            ->groupBy(fn (Client $c) => LeadContactProvisioner::normalizeAddressKey($c->address))
            ->filter(fn (Collection $g) => $g->count() > 1 && $g->first()->address !== null);

        if ($groups->isEmpty()) {
            $this->info('No duplicate clients found.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $merged = 0;
        $skipped = 0;

        foreach ($groups as $key => $group) {
            /** @var Collection<int, Client> $group */
            $userSets = $group
                ->map(fn (Client $c) => $c->users->pluck('id')->sort()->values()->all())
                ->filter(fn ($set) => ! empty($set))
                ->map(fn ($set) => implode(',', $set))
                ->unique()
                ->values();

            $zips = $group
                ->pluck('zip_code')
                ->filter(fn ($z) => ! empty($z))
                ->map(fn ($z) => (string) $z)
                ->unique()
                ->values();

            $mergeable = $userSets->count() <= 1 && $zips->count() <= 1;

            if (! $mergeable && ! $force) {
                $this->newLine();
                $reason = $userSets->count() > 1 ? 'conflicting user sets' : 'conflicting zips';
                $this->warn("SKIP {$key} — {$reason}:");
                foreach ($group as $c) {
                    $uids = $c->users->pluck('id')->implode(',');
                    $this->line("   [{$c->id}] {$c->address} | {$c->zip_code} | users=[{$uids}]");
                }
                $skipped++;
                continue;
            }

            $canonical = $this->pickCanonical($group);

            $this->newLine();
            $this->info("MERGE {$key} → canonical [{$canonical->id}] {$canonical->address}");
            foreach ($group as $c) {
                $marker = $c->id === $canonical->id ? '*' : ' ';
                $uids = $c->users->pluck('id')->implode(',');
                $this->line("  {$marker}[{$c->id}] {$c->address} | {$c->city} | {$c->zip_code} | users=[{$uids}]");
            }

            $duplicates = $group->filter(fn (Client $c) => $c->id !== $canonical->id);

            if ($dryRun) {
                $merged += $duplicates->count();
                continue;
            }

            DB::transaction(function () use ($canonical, $duplicates, &$merged) {
                foreach ($duplicates as $dupe) {
                    $this->mergeInto($canonical, $dupe);
                    $merged++;
                }
                $this->backfillCanonical($canonical->fresh(), $duplicates);
            });
        }

        $this->newLine();
        $verb = $dryRun ? 'Would merge' : 'Merged';
        $this->info("{$verb} {$merged} duplicate clients across ".$groups->count().' group(s). Skipped: '.$skipped);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Client>  $group
     */
    protected function pickCanonical(Collection $group): Client
    {
        return $group
            ->sort(function (Client $a, Client $b) {
                $byUsers = $b->users->count() <=> $a->users->count();
                if ($byUsers !== 0) {
                    return $byUsers;
                }
                $byCompleteness = $this->completeness($b) <=> $this->completeness($a);
                if ($byCompleteness !== 0) {
                    return $byCompleteness;
                }
                return $a->id <=> $b->id;
            })
            ->first();
    }

    protected function completeness(Client $c): int
    {
        return (int) ! empty($c->city) + (int) ! empty($c->state) + (int) ! empty($c->zip_code);
    }

    protected function isCleanAddress(?string $address): bool
    {
        if ($address === null || trim($address) === '') {
            return false;
        }
        $tokens = preg_split('/\s+/', strtolower(trim((string) preg_replace('/[^a-z0-9 ]+/i', ' ', $address)))) ?: [];
        $tokens = array_values(array_filter($tokens));
        if (count($tokens) < 2) {
            return false;
        }
        $key = LeadContactProvisioner::normalizeAddressKey($address);
        $keyTokenCount = count(explode(' ', $key));
        return $keyTokenCount === count($tokens);
    }

    protected function mergeInto(Client $canonical, Client $dupe): void
    {
        // client_user pivot: move users not already attached to canonical.
        $existingUserIds = DB::table('client_user')->where('client_id', $canonical->id)->pluck('user_id')->all();
        DB::table('client_user')
            ->where('client_id', $dupe->id)
            ->whereNotIn('user_id', $existingUserIds)
            ->update(['client_id' => $canonical->id]);
        DB::table('client_user')->where('client_id', $dupe->id)->delete();

        // client_vendor pivot: move vendor links not already attached.
        $existingVendorIds = DB::table('client_vendor')->where('client_id', $canonical->id)->pluck('vendor_id')->all();
        DB::table('client_vendor')
            ->where('client_id', $dupe->id)
            ->whereNotIn('vendor_id', $existingVendorIds)
            ->update(['client_id' => $canonical->id]);
        DB::table('client_vendor')->where('client_id', $dupe->id)->delete();

        // Direct FK reassignments.
        DB::table('projects')->where('client_id', $dupe->id)->update(['client_id' => $canonical->id]);
        DB::table('project_vendor')->where('client_id', $dupe->id)->update(['client_id' => $canonical->id]);
        DB::table('call_logs')->where('client_id', $dupe->id)->update(['client_id' => $canonical->id]);
        DB::table('sms_group_threads')->where('client_id', $dupe->id)->update(['client_id' => $canonical->id]);

        DB::table('clients')->where('id', $dupe->id)->delete();
    }

    /**
     * @param  Collection<int, Client>  $duplicates
     */
    protected function backfillCanonical(Client $canonical, Collection $duplicates): void
    {
        $dirty = false;
        foreach (['city', 'state', 'zip_code', 'home_phone', 'business_name', 'address_2'] as $field) {
            if (! empty($canonical->{$field})) {
                continue;
            }
            foreach ($duplicates as $dupe) {
                if (! empty($dupe->{$field})) {
                    $canonical->{$field} = $dupe->{$field};
                    $dirty = true;
                    break;
                }
            }
        }

        // Pick the cleanest address: prefer ones where the street suffix is the
        // final token (no city/state pollution), then the longest expansion.
        $candidates = collect([$canonical])->concat($duplicates);
        $best = $candidates
            ->sort(function (Client $a, Client $b) {
                $aClean = $this->isCleanAddress($a->address) ? 1 : 0;
                $bClean = $this->isCleanAddress($b->address) ? 1 : 0;
                if ($aClean !== $bClean) {
                    return $bClean <=> $aClean;
                }
                return mb_strlen((string) $b->address) <=> mb_strlen((string) $a->address);
            })
            ->first();

        if ($best && $best->address !== $canonical->address) {
            $canonical->address = $best->address;
            $dirty = true;
        }

        if ($dirty) {
            $canonical->save();
        }
    }
}
