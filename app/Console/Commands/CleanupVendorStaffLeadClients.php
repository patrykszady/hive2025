<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupVendorStaffLeadClients extends Command
{
    protected $signature = 'clients:cleanup-vendor-staff-leads
                            {--dry-run : Preview changes without modifying records}';

    protected $description = 'Delete Client rows that were created from leads where the contact is actually staff of the lead\'s vendor (self-submissions / test leads).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Build candidate set: clients linked (via client_vendor) to a vendor
        // where every attached user is also staff of that vendor.
        $candidates = Client::withoutGlobalScopes()
            ->with(['users:id,primary_vendor_id', 'users.vendors:id', 'vendors:id', 'users.clients'])
            ->whereHas('users')
            ->whereHas('vendors')
            ->get();

        $toDelete = $candidates->filter(function (Client $client) {
            $vendorIds = $client->vendors->pluck('id')->all();
            if ($client->users->isEmpty() || empty($vendorIds)) {
                return false;
            }
            // Every user must be staff of every linked vendor.
            foreach ($client->users as $user) {
                foreach ($vendorIds as $vid) {
                    if (! $this->userIsStaffOfVendor($user, $vid)) {
                        return false;
                    }
                }
            }
            return true;
        });

        if ($toDelete->isEmpty()) {
            $this->info('No vendor-staff lead clients found.');
            return self::SUCCESS;
        }

        $deleted = 0;
        $skipped = 0;

        foreach ($toDelete as $client) {
            $relatedCounts = [
                'projects' => DB::table('projects')->where('client_id', $client->id)->count(),
                'project_vendor' => DB::table('project_vendor')->where('client_id', $client->id)->count(),
                'call_logs' => DB::table('call_logs')->where('client_id', $client->id)->count(),
                'sms_group_threads' => DB::table('sms_group_threads')->where('client_id', $client->id)->count(),
            ];

            $hasRelated = array_sum($relatedCounts) > 0;

            $userLabels = $client->users
                ->map(fn (User $u) => $u->id.':'.trim((string) $u->first_name.' '.(string) $u->last_name))
                ->implode(', ');

            if ($hasRelated) {
                $this->warn("SKIP [{$client->id}] {$client->address} — has related rows: ".json_encode($relatedCounts).' | users='.$userLabels);
                $skipped++;
                continue;
            }

            $this->line(($dryRun ? 'WOULD DELETE' : 'DELETE')." [{$client->id}] {$client->address} | users={$userLabels}");

            if ($dryRun) {
                $deleted++;
                continue;
            }

            DB::transaction(function () use ($client) {
                DB::table('client_user')->where('client_id', $client->id)->delete();
                DB::table('client_vendor')->where('client_id', $client->id)->delete();
                DB::table('clients')->where('id', $client->id)->delete();
            });
            $deleted++;
        }

        $this->newLine();
        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$deleted} client(s). Skipped (had related data): {$skipped}");

        if (! $dryRun && $deleted > 0) {
            $this->info('Reindex Scout: php artisan scout:flush "App\\Models\\Client" && php artisan scout:import "App\\Models\\Client"');
        }

        return self::SUCCESS;
    }

    protected function userIsStaffOfVendor(User $user, int $vendorId): bool
    {
        if ((int) ($user->primary_vendor_id ?? 0) === $vendorId) {
            return true;
        }
        return $user->vendors->contains(fn ($v) => (int) $v->id === $vendorId);
    }
}
