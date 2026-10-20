<?php

namespace App\Services\Projects;

use App\Models\EmailTracking;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * A simplified, read-only version of
 * App\Livewire\Projects\EmailTrackingTable::emailTrackingEvents() for the
 * app's Projects tab — the unscoped "Email Tracking" card beside the
 * project list, reactive to the same Client filter
 * (resources/views/livewire/projects/index.blade.php's
 * `<livewire:projects.email-tracking-table :client-id="$client_id" />`).
 *
 * Unlike the web table, this does NOT infer a thread for an event with no
 * thread_id/message_id by matching recipient + a time window, does not
 * merge consecutive "opened" events that share no known send, and does
 * not let a later reply move a thread's "latest" event backwards — all of
 * that exists there to patch gaps in older data; a recent send always
 * carries a thread_id. It DOES keep the two things that actually change
 * what gets shown: resolving a recipient address to a known User (so the
 * card can show a name, not just an email) and excluding a vendor's own
 * team members' opens/clicks (only a CLIENT's engagement is interesting
 * here) — same reasoning, config and queries as the web table's own setup
 * step. Reuses AppVendorPaymentEmailTracking's "group by thread, newest
 * wins" shape (same simplification there, for Vendor Payment threads).
 */
class AppEmailTrackingDirectory
{
    public const PER_PAGE = 10;

    /**
     * @return LengthAwarePaginator<int, array{id: int, event_type: string, event_count: int, email_template_name: ?string, project: ?array{id: int, name: string}, recipients: array{names: list<string>, emails: list<string>}, event_at: ?string}>
     */
    public function events(?int $clientId, int $page = 1, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $page = max(1, $page);

        $query = EmailTracking::with('project')->clientFacing();

        if ($clientId !== null) {
            $query->forClientAndItsLeads($clientId);
        }

        $allEvents = $query->orderByDesc('event_at')->get();

        if ($allEvents->isEmpty()) {
            return new LengthAwarePaginator(collect(), 0, $perPage, $page);
        }

        $vendorTeamEmails = $this->vendorTeamEmails($allEvents);
        $excludedEmails = $this->excludedRecipientEmails();
        $usersByEmail = $this->usersByEmail($allEvents);

        $threads = $allEvents
            ->groupBy(fn (EmailTracking $event) => $event->thread_id ?: ($event->message_id ?: 'row:'.$event->id))
            ->map(fn (Collection $threadEvents) => $this->threadRow($threadEvents, $vendorTeamEmails, $excludedEmails, $usersByEmail))
            ->sortByDesc(fn (array $row) => $row['event_at'] ?? '')
            ->values();

        $total = $threads->count();
        $items = $threads->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator($items, $total, $perPage, $page);
    }

    /**
     * @param  Collection<int, EmailTracking>  $threadEvents
     * @return array{id: int, event_type: string, event_count: int, email_template_name: ?string, project: ?array{id: int, name: string}, recipients: array{names: list<string>, emails: list<string>}, event_at: ?string}
     */
    private function threadRow(Collection $threadEvents, Collection $vendorTeamEmails, Collection $excludedEmails, Collection $usersByEmail): array
    {
        $sent = $threadEvents->firstWhere('event_type', 'sent');
        $latest = $threadEvents->sortByDesc('event_at')->first();

        $sentMetadata = is_array($sent?->metadata) ? $sent->metadata : [];
        $ignoreEmails = collect([$sentMetadata['from_email'] ?? null, $sentMetadata['sender_email'] ?? null])
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->values();

        $shouldIgnore = function ($email) use ($ignoreEmails, $vendorTeamEmails, $excludedEmails): bool {
            if (! is_string($email) || trim($email) === '') {
                return true;
            }
            $email = strtolower(trim($email));

            return $excludedEmails->contains($email) || $ignoreEmails->contains($email) || $vendorTeamEmails->contains($email);
        };

        $rawRecipients = collect($latest->recipient_emails ?? [])->filter(fn ($email) => is_string($email) && $email !== '');
        $recipientEmails = $rawRecipients->reject($shouldIgnore)->values();

        // The team-email filter can leave nothing (e.g. a test send to
        // yourself) — fall back to the raw recipients rather than an empty row.
        if ($recipientEmails->isEmpty()) {
            $recipientEmails = $rawRecipients->values();
        }

        $recipientUsers = $recipientEmails
            ->map(fn (string $email) => $usersByEmail->get(strtolower($email)))
            ->filter()
            ->values();

        return [
            'id' => (int) $latest->id,
            'event_type' => (string) $latest->event_type,
            'event_count' => $threadEvents->count(),
            'email_template_name' => $latest->email_template_name,
            'project' => $latest->project ? ['id' => $latest->project->id, 'name' => $latest->project->name] : null,
            'recipients' => [
                'names' => $recipientUsers->map(fn (User $user) => $user->full_name)->filter()->values()->all(),
                'emails' => $recipientEmails->all(),
            ],
            'event_at' => ($sent?->event_at ?? $latest->event_at)?->toIso8601String(),
        ];
    }

    /** @return Collection<int, string> lowercased team-member emails, for excluding their own opens. */
    private function vendorTeamEmails(Collection $events): Collection
    {
        $vendorIds = $events->pluck('belongs_to_vendor_id')->filter()->unique()->values();

        if ($vendorIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereHas('vendors', fn ($q) => $q->whereIn('vendors.id', $vendorIds))
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->filter(fn (string $email): bool => $email !== '')
            ->unique()
            ->values();
    }

    /** @return Collection<int, string> */
    private function excludedRecipientEmails(): Collection
    {
        return collect((array) config('email_tracking.excluded_recipients', []))
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->unique()
            ->values();
    }

    /** @return Collection<string, User> keyed by lowercase email. */
    private function usersByEmail(Collection $events): Collection
    {
        $allEmails = $events->pluck('recipient_emails')->flatten()->filter()->unique()->values()->all();

        if ($allEmails === []) {
            return collect();
        }

        return User::query()->whereIn('email', $allEmails)->get()
            ->keyBy(fn (User $user) => strtolower((string) $user->email));
    }
}
