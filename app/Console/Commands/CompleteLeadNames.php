<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadContactProvisioner;
use App\Support\SenderName;
use Illuminate\Console\Command;

/**
 * Leads that arrived with a first name only ("Valina") and no contact,
 * whose message or email address gives the surname away: complete the name
 * and provision them the way a new lead is provisioned today. Safe to run
 * again.
 */
class CompleteLeadNames extends Command
{
    protected $signature = 'leads:complete-names
        {--apply : Write the changes (without this it only reports what it would do)}
        {--limit=500 : Most contact-less leads to examine}';

    protected $description = 'Complete first-name-only leads from their message or email address and provision their contact';

    public function handle(LeadContactProvisioner $provisioner): int
    {
        $apply = (bool) $this->option('apply');
        $found = 0;
        $linked = 0;

        $leads = Lead::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNull('user_id')
            ->with('last_status')
            ->latest('id')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($leads as $lead) {
            // A lead already won, lost or dismissed is history; renaming it now helps nobody.
            if (in_array($lead->last_status?->title, ['Won', 'Lost', 'Not a Fit'], true)) {
                continue;
            }

            $data = (array) $lead->lead_data;
            $name = implode(' ', SenderName::nameWords((string) ($data['name'] ?? '')));

            if ($name === '' || str_contains($name, ' ')) {
                continue;
            }

            $completed = SenderName::completeFromMessage($name, $data['email'] ?? null, $data['message'] ?? null);
            if ($completed === null || $completed === $name) {
                continue;
            }

            $found++;
            $this->line("lead {$lead->id}: {$name} → {$completed}".($apply ? '' : ' [preview]'));

            if (! $apply) {
                continue;
            }

            $data['name'] = $completed;
            $lead->lead_data = $data;
            $lead->saveQuietly();

            $provisioner->provision($lead->fresh());
            $fresh = $lead->fresh();

            if ($fresh->user_id) {
                $linked++;
                $this->line("  linked to user {$fresh->user_id}");
            } else {
                $this->line('  still no contact: a phone number and an email are needed too');
            }
        }

        $this->info($apply
            ? "{$found} lead".($found === 1 ? '' : 's').' completed, '.$linked.' linked.'
            : "{$found} lead".($found === 1 ? '' : 's').' would be completed. Run with --apply to write.');

        return self::SUCCESS;
    }
}
