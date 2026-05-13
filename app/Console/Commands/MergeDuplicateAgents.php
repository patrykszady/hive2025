<?php

namespace App\Console\Commands;

use App\Models\Agent;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MergeDuplicateAgents extends Command
{
    protected $signature = 'agents:merge-duplicates
                            {--dry-run : Preview changes without updating records}
                            {--business= : Limit to a single business_name match (contains)}';

    protected $description = 'Normalize duplicate agent addresses within each business (case/spacing variants).';

    public function handle(): int
    {
        $agents = Agent::query()
            ->when($this->option('business'), function ($query, $business) {
                $query->where('business_name', 'like', '%' . $business . '%');
            })
            ->whereNotNull('business_name')
            ->whereNotNull('address')
            ->orderBy('business_name')
            ->orderBy('address')
            ->get();

        if ($agents->isEmpty()) {
            $this->info('No agents found for normalization.');

            return self::SUCCESS;
        }

        $groups = $agents
            ->groupBy(fn (Agent $agent) => $this->normalizedValue($agent->business_name) . '|' . $this->normalizedAddress($agent->address))
            ->filter(fn (Collection $group) => $group->count() > 1);

        if ($groups->isEmpty()) {
            $this->info('No duplicate address variants found.');

            return self::SUCCESS;
        }

        $updatedRows = 0;
        $groupCount = 0;

        foreach ($groups as $group) {
            /** @var Collection<int, Agent> $group */
            $groupCount++;
            $canonicalAddress = $this->canonicalAddress($group);
            $businessName = (string) ($group->first()->business_name ?? 'Unknown Business');

            $this->newLine();
            $this->info("Business: {$businessName}");
            $this->line("Canonical: {$canonicalAddress}");

            foreach ($group as $agent) {
                $this->line(" - #{$agent->id} {$agent->name} <{$agent->email}> | {$agent->address}");
            }

            $needsUpdate = $group->filter(fn (Agent $agent) => trim((string) $agent->address) !== $canonicalAddress);

            if ($needsUpdate->isEmpty()) {
                continue;
            }

            if ($this->option('dry-run')) {
                $updatedRows += $needsUpdate->count();
                continue;
            }

            DB::transaction(function () use ($needsUpdate, $canonicalAddress, &$updatedRows): void {
                foreach ($needsUpdate as $agent) {
                    $agent->update(['address' => $canonicalAddress]);
                    $updatedRows++;
                }
            });
        }

        $this->newLine();
        $this->info("Duplicate groups processed: {$groupCount}");

        if ($this->option('dry-run')) {
            $this->warn("Dry run: {$updatedRows} records would be updated.");

            return self::SUCCESS;
        }

        $this->info("Updated {$updatedRows} agent records.");

        return self::SUCCESS;
    }

    private function normalizedValue(?string $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    private function normalizedAddress(?string $address): string
    {
        return $this->normalizedValue($address);
    }

    private function canonicalAddress(Collection $group): string
    {
        $ranked = $group
            ->groupBy(fn (Agent $agent) => $this->cleanAddress((string) $agent->address))
            ->map(function (Collection $addressGroup, string $address): array {
                return [
                    'address' => $address,
                    'count' => $addressGroup->count(),
                    'lowercase_score' => preg_match_all('/[a-z]/', $address) ?: 0,
                    'length' => strlen($address),
                ];
            })
            ->values()
            ->sort(function (array $a, array $b): int {
                return [$b['lowercase_score'], $b['count'], $b['length']] <=> [$a['lowercase_score'], $a['count'], $a['length']];
            })
            ->values();

        return (string) ($ranked->first()['address'] ?? $this->cleanAddress((string) $group->first()->address));
    }

    private function cleanAddress(string $address): string
    {
        return preg_replace('/\s+/', ' ', trim($address)) ?: '';
    }
}
