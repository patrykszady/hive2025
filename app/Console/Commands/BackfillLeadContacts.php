<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\CrewEmailIngest;
use App\Models\Lead;
use App\Services\LeadAddressCompleter;
use App\Services\LeadContactProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * One-off catch-up for leads imported before the contact/address rules
 * existed: those runs bailed on a two-number phone string, refused to create a
 * contact without a phone, or created a client from a bare street.
 *
 * For each incomplete lead it does what import now does — recovers the CC'd
 * addresses from the mailbox row, completes the address, then re-runs
 * provisioning. Every step only ever FILLS BLANKS: nothing already on a lead,
 * contact or client is overwritten, so it's safe to run more than once.
 *
 * Previews by default; pass --apply to write.
 */
class BackfillLeadContacts extends Command
{
    protected $signature = 'leads:backfill-contacts
        {--apply : Write the changes (without this it only reports what it would do)}
        {--lead= : Restrict to one lead id}
        {--limit=200 : Most leads to examine}';

    protected $description = 'Complete addresses and provision contacts/clients for leads imported before those rules existed.';

    public function handle(LeadAddressCompleter $completer, LeadContactProvisioner $provisioner): int
    {
        $apply = (bool) $this->option('apply');

        $leads = $this->candidates();

        if ($leads->isEmpty()) {
            $this->info('Nothing to backfill — every lead already has a complete contact and client.');

            return self::SUCCESS;
        }

        $this->line($apply
            ? "Backfilling {$leads->count()} lead(s)."
            : "DRY RUN — {$leads->count()} lead(s) would be touched. Re-run with --apply to write.");
        $this->newLine();

        $stats = ['addresses' => 0, 'cc_emails' => 0, 'contacts' => 0, 'clients' => 0, 'ambiguous' => 0, 'still_short' => 0];

        foreach ($leads as $lead) {
            $before = $this->snapshot($lead);
            $changes = [];

            $data = (array) $lead->lead_data;

            // 1. The partner's address is still on the mailbox row even when
            //    the lead was stored before we started keeping it.
            $cc = $this->recoverCcEmails($lead, $data);
            if ($cc !== []) {
                $data['cc_emails'] = $cc;
                $changes[] = 'cc_emails: '.implode(', ', $cc);
                $stats['cc_emails']++;
            }

            // 2. Fill in city/state/ZIP (or record the candidates when the
            //    street matches more than one town).
            $completed = $completer->complete($data);

            foreach (['city', 'state', 'zip'] as $field) {
                if (empty($data[$field]) && ! empty($completed[$field])) {
                    $changes[] = "{$field}: {$completed[$field]}";
                }
            }

            if (! empty($completed['address_candidates'])) {
                $stats['ambiguous']++;
                $changes[] = count($completed['address_candidates']).' address candidates — needs a human to choose';
            } elseif ($completed !== $data) {
                $stats['addresses']++;
            }

            $data = $completed;

            if ($apply) {
                $lead->lead_data = $data;
                $lead->saveQuietly();

                try {
                    $provisioner->provision($lead->fresh());
                } catch (\Throwable $e) {
                    $this->error("  lead {$lead->id}: provisioning failed — {$e->getMessage()}");

                    continue;
                }
            }

            $after = $apply ? $this->snapshot($lead->fresh()) : $before;

            if ($apply) {
                if (! $before['user_id'] && $after['user_id']) {
                    $changes[] = 'contact created';
                    $stats['contacts']++;
                }
                if (! $before['client'] && $after['client']) {
                    $changes[] = 'client created';
                    $stats['clients']++;
                } elseif ($before['client'] && $after['client'] && $before['client'] !== $after['client']) {
                    $changes[] = 'client completed: '.$after['client'];
                    $stats['clients']++;
                }

                if ($this->stillIncomplete($lead->fresh())) {
                    $stats['still_short']++;
                }
            }

            $this->line(sprintf(
                '  lead %-5s %-28s %s',
                $lead->id,
                \Illuminate\Support\Str::limit((string) ($data['name'] ?? '—'), 26),
                $changes === [] ? 'nothing to add' : implode(' | ', $changes),
            ));
        }

        $this->newLine();
        $this->table(
            ['addresses completed', 'cc emails recovered', 'contacts created', 'clients created/completed', 'ambiguous addresses', 'still incomplete'],
            [[$stats['addresses'], $stats['cc_emails'], $stats['contacts'], $stats['clients'], $stats['ambiguous'], $stats['still_short']]],
        );

        if ($stats['ambiguous'] > 0) {
            $this->warn('Some streets match more than one town. Open those leads and pick the right address — the modal lists them nearest-office first.');
        }

        if (! $apply) {
            $this->newLine();
            $this->info('Nothing was written. Re-run with --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Leads worth looking at: no contact, no client, or a client whose address
     * is short of what a client record needs.
     *
     * @return Collection<int, Lead>
     */
    private function candidates(): Collection
    {
        return Lead::withoutGlobalScopes()
            ->when($this->option('lead'), fn ($q) => $q->whereKey((int) $this->option('lead')))
            ->latest('id')
            ->limit((int) $this->option('limit'))
            ->get()
            ->filter(fn (Lead $lead) => $this->stillIncomplete($lead))
            ->values();
    }

    private function stillIncomplete(Lead $lead): bool
    {
        $user = $lead->user_id
            ? \App\Models\User::withoutGlobalScopes()->find($lead->user_id)
            : null;

        if (! $user) {
            return true;
        }

        $clients = Client::withoutGlobalScopes()
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
            ->get();

        if ($clients->isEmpty()) {
            return true;
        }

        return $clients->every(fn (Client $client) => trim((string) $client->address) === ''
            || trim((string) $client->city) === ''
            || trim((string) $client->state) === ''
            || trim((string) $client->zip_code) === '');
    }

    /**
     * The addresses on the original message that belong to the enquirers —
     * every CC except the sender and our own mailboxes.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function recoverCcEmails(Lead $lead, array $data): array
    {
        if (! empty($data['cc_emails'])) {
            return [];
        }

        $ingest = CrewEmailIngest::query()->where('lead_id', $lead->id)->first();

        if (! $ingest) {
            return [];
        }

        $ours = CompanyEmail::query()
            ->withoutGlobalScopes()
            ->pluck('email')
            ->push((string) config('nylas.crew_leads.mailbox'))
            ->filter()
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->all();

        $from = mb_strtolower((string) ($ingest->from_email ?? ''));

        return collect($ingest->recipients['cc'] ?? [])
            ->merge($ingest->recipients['to'] ?? [])
            ->filter(fn ($email) => is_string($email) && $email !== '')
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->reject(fn (string $email) => $email === $from || in_array($email, $ours, true))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{user_id: ?int, client: ?string} */
    private function snapshot(Lead $lead): array
    {
        $user = $lead->user_id
            ? \App\Models\User::withoutGlobalScopes()->find($lead->user_id)
            : null;

        $client = $user
            ? Client::withoutGlobalScopes()->whereHas('users', fn ($q) => $q->where('users.id', $user->id))->first()
            : null;

        return [
            'user_id' => $user?->id,
            'client' => $client
                ? trim(collect([$client->address, $client->city, trim(($client->state ?? '').' '.($client->zip_code ?? ''))])->filter()->implode(', '))
                : null,
        ];
    }
}
