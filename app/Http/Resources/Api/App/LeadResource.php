<?php

namespace App\Http\Resources\Api\App;

use App\Models\Lead;
use App\Services\Leads\LeadContactCompletion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A lead as the app's Leads tab shows it — same statuses, filters and
 * detail fields as App\Livewire\Leads\LeadsIndex/LeadCreate. With
 * withDetail() it adds the message, attachments, availability and delete
 * impact the detail screen needs beyond the list row.
 *
 * @mixin \App\Models\Lead
 */
class LeadResource extends JsonResource
{
    protected bool $detail = false;

    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Lead $lead */
        $lead = $this->resource;
        $client = $lead->resolveClient();
        $addressParts = $lead->shortAddressParts();
        $status = $lead->last_status?->title ?? 'New';

        $row = [
            'id' => $lead->id,
            'date' => $lead->date?->toIso8601String(),
            'origin' => $lead->origin,
            'status' => [
                'code' => $status,
                'label' => $status,
                'color' => Lead::statusColor($status),
            ],
            'name' => trim((string) ($lead->lead_data['name'] ?? '')),
            'email' => $lead->lead_data['email'] ?? null,
            'phone' => $lead->lead_data['phone'] ?? null,
            'address' => [
                'city' => $addressParts['city'],
                'street' => $addressParts['street'],
                'full' => $lead->lead_data['address'] ?? null,
            ],
            'client' => $client ? ['id' => $client->id, 'name' => $client->name] : null,
            'feedback_count' => $this->whenCounted('feedback', fn () => $this->feedback_count, null),
            // The list query withCount()s feedback but never loads the
            // relation itself (too expensive per row); the detail query
            // loads the relation but never counts it. Prefer whichever one
            // this lead actually has, so the list's "Left feedback" icon
            // (leads/index.blade.php's `@if ($lead->feedback_count > 0)`)
            // shows under the same condition here as it does on the web.
            'has_feedback' => $lead->relationLoaded('feedback')
                ? $lead->feedback->isNotEmpty()
                : $this->whenCounted('feedback', fn () => $this->feedback_count > 0, null),
            // The "Last" column — the latest status's date, but only for a
            // status that isn't one of the lifecycle bookends (mirrors
            // leads/index.blade.php's `@if($lead->last_status && !in_array(...))`).
            'last_status_at' => ($lead->last_status !== null && ! in_array($status, ['New', 'Won', 'Lost', 'Not a Fit'], true))
                ? $lead->last_status->created_at?->toIso8601String()
                : null,
            'created_at' => $lead->created_at?->toIso8601String(),
            'web_path' => '/leads?lead='.$lead->id,
        ];

        if (! $this->detail) {
            return $row;
        }

        $hasReplied = $status === 'Replied' && ! $this->lastEmailBounced($lead);
        $completion = app(LeadContactCompletion::class);

        return $row + [
            'message' => $lead->lead_data['message'] ?? null,
            'notes' => $lead->notes,
            'reply_to_email' => $lead->lead_data['reply_to_email'] ?? null,
            // The linked contact — distinct from `client` above, which is
            // null until the contact also has an address on file. Mirrors
            // details-panel.blade.php's "User" card (the contact with no
            // client yet).
            'contact' => $lead->user ? [
                'id' => $lead->user->id,
                'name' => $lead->user->full_name,
                'email' => $lead->user->email,
                'phone' => $lead->user->cell_phone,
            ] : null,
            // What's still missing before this lead can be replied to, and
            // the tools the Details tab offers to fill each one in — mirrors
            // LeadCreate::missingContactInfo()/needsPhone()/addressCandidates().
            'missing_contact_info' => $completion->missingContactInfo($lead, $client),
            'needs_phone' => $completion->needsPhone($lead),
            'address_candidates' => $completion->addressCandidates($lead),
            // Raw address parts — mirrors LeadCreate's edit() fields exactly,
            // so an "Edit" form can prefill city/state/zip without re-parsing
            // the combined 'address' string.
            'city' => $lead->lead_data['city'] ?? null,
            'state' => $lead->lead_data['state'] ?? null,
            'zip' => $lead->lead_data['zip'] ?? null,
            'attachments' => $this->attachments($lead),
            'email_replies' => $this->emailReplies($lead),
            'availability' => $lead->usableAvailability(),
            'has_rescheduled' => $lead->hasRescheduled(),
            'allows_consult_within_the_hour' => $lead->allowsConsultWithinTheHour(),
            'status_history' => $lead->statuses->sortByDesc('id')->map(fn ($status) => [
                'title' => $status->title,
                'created_at' => $status->created_at?->toIso8601String(),
            ])->values()->all(),
            'feedback' => $lead->feedback->map(fn ($feedback) => [
                'id' => $feedback->id,
                'rating' => $feedback->rating,
                'message' => $feedback->message,
                'created_at' => $feedback->created_at?->toIso8601String(),
            ])->values()->all(),
            'has_replied' => $hasReplied,
            'can_delete' => ! $hasReplied,
            'delete_impact' => $lead->deleteImpact(),
        ];
    }

    /**
     * @return list<array{index: int, name: string, mime: ?string, url: string}>
     */
    private function attachments(Lead $lead): array
    {
        $files = (array) ($lead->lead_data['attachments'] ?? []);

        return collect($files)
            ->values()
            ->map(fn ($file, int $index) => [
                'index' => $index,
                'name' => (string) ($file['name'] ?? 'attachment'),
                'mime' => $file['mime'] ?? null,
                'url' => URL::temporarySignedRoute('leads.file.signed', now()->addHours(24), ['lead' => $lead->id, 'index' => $index]),
            ])
            ->all();
    }

    /**
     * What the lead has written back — mirrors the details-panel partial's
     * "Their replies" card (lead_data.email_replies, filed by the crew@
     * ingest), newest first same as the web shows them.
     *
     * @return list<array{subject: ?string, body: ?string, at: ?string}>
     */
    private function emailReplies(Lead $lead): array
    {
        $replies = (array) ($lead->lead_data['email_replies'] ?? []);

        return collect($replies)
            ->map(fn ($reply) => [
                'subject' => $reply['subject'] ?? null,
                'body' => $reply['body'] ?? null,
                'at' => $reply['at'] ?? null,
            ])
            ->values()
            ->all();
    }

    /** Mirrors LeadCreate::lastEmailBounced() — judged on the latest delivery outcome only. */
    private function lastEmailBounced(Lead $lead): bool
    {
        $outcome = \App\Models\EmailTracking::withoutGlobalScopes()
            ->where('lead_id', $lead->id)
            ->whereIn('event_type', ['bounced', 'delivered', 'opened', 'clicked'])
            ->orderByDesc('event_at')
            ->orderByDesc('id')
            ->value('event_type');

        return $outcome === 'bounced';
    }
}
