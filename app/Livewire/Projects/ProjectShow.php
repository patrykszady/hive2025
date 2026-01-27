<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\HasToJsonMethod;
use App\Models\EmailTracking;
use App\Models\Project;
use App\Models\User;
use App\Support\ProjectDocumentGenerator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectShow extends Component
{
    use AuthorizesRequests;
    use HasToJsonMethod;
    use WithPagination;

    public Project $project;

    public $estimates = [];

    protected $listeners = ['refreshComponent' => '$refresh'];

    public $showEmailTracking = false;
    public $showDistributions = false;

    public function mount()
    {
        // Only load critical relationships for initial render
        $this->project->load(['latestStatus']);
        
        $this->estimates = [];
    }

    public function loadEmailTracking()
    {
        $this->showEmailTracking = true;
    }

    public function loadDistributions()
    {
        $this->project->load('distributions');
        $this->showDistributions = true;
    }

    public function print_reimbursements(): StreamedResponse
    {
        $this->authorize('view', $this->project);

        $document = ProjectDocumentGenerator::generateReimbursements($this->project);

        return response()->streamDownload(function () use ($document) {
            echo $document['binary'];
        }, $document['filename'], [
            'Content-Type' => 'application/pdf',
        ]);
    }

    #[Computed]
    public function emailTrackingEvents()
    {
        if (!$this->showEmailTracking) {
            return collect();
        }

        $events = EmailTracking::with('project')
            ->where('project_id', $this->project->id)
            ->orderBy('event_at', 'DESC')
            ->get();
        
        // Get all unique emails and fetch users in one query
        $allEmails = $events->pluck('recipient_emails')->flatten()->unique();
        $usersByEmail = User::whereIn('email', $allEmails)->get()->keyBy('email');
        
        $events = $events->groupBy(function ($event) {
            return $event->thread_id ?: $event->message_id;
        })
            ->map(function ($threadEvents) use ($usersByEmail) {
                // Prioritize 'replied' as the main event, even if not the latest chronologically
                $repliedEvent = $threadEvents->firstWhere('event_type', 'replied');
                $mainEvent = $repliedEvent ?? $threadEvents->first();

                $sentEvent = $threadEvents->firstWhere('event_type', 'sent');
                $sentMetadata = is_array($sentEvent?->metadata) ? $sentEvent->metadata : [];
                $ignoreEmails = collect(array_filter([
                    is_string($sentMetadata['from_email'] ?? null) ? (string) $sentMetadata['from_email'] : null,
                    is_string($sentMetadata['sender_email'] ?? null) ? (string) $sentMetadata['sender_email'] : null,
                ]))
                    ->filter(fn ($email) => is_string($email) && trim($email) !== '')
                    ->map(fn ($email) => strtolower(trim($email)))
                    ->unique()
                    ->values()
                    ->all();

                $shouldIgnoreRecipient = function ($email) use ($ignoreEmails): bool {
                    if (! is_string($email) || trim($email) === '') {
                        return true;
                    }

                    $email = strtolower(trim($email));

                    if (in_array($email, $ignoreEmails, true)) {
                        return true;
                    }

                    return false;
                };
                
                // Get all unique recipient emails from all events in this thread
                $threadRecipientEmails = $threadEvents
                    ->pluck('recipient_emails')
                    ->flatten()
                    ->reject($shouldIgnoreRecipient)
                    ->unique()
                    ->values()
                    ->all();

                $mainEventType = (string) ($mainEvent->event_type ?? '');

                $sameTypeEvents = $threadEvents
                    ->where('event_type', $mainEventType)
                    ->values();

                // If the main row is an event type that can happen per-recipient (opened/delivered/clicked),
                // collapse all same-type events into the main row.
                $shouldCollapseSameType = in_array($mainEventType, ['opened', 'delivered', 'clicked', 'link_clicked'], true);

                $eventRecipientEmails = ($shouldCollapseSameType && $sameTypeEvents->count() > 1)
                    ? $sameTypeEvents->pluck('recipient_emails')->flatten()
                    : collect($mainEvent->recipient_emails ?? []);

                $eventRecipientEmails = $eventRecipientEmails
                    ->flatten()
                    ->filter()
                    ->reject($shouldIgnoreRecipient)
                    ->unique()
                    ->values()
                    ->all();
                
                // Map emails to users using pre-fetched collection
                $users = collect($eventRecipientEmails)
                    ->map(fn($email) => $usersByEmail->get($email))
                    ->filter()
                    ->values();
                
                // Add recipient user data to the main event
                $mainEvent->recipient_users = $users;
                $mainEvent->all_recipient_emails = ! empty($eventRecipientEmails) ? $eventRecipientEmails : $threadRecipientEmails;

                if ($shouldCollapseSameType && $sameTypeEvents->count() > 1) {
                    $mainEvent->event_count = $sameTypeEvents->count();
                }
                
                // Group consecutive events of the same type (excluding the main event)
                $groupedEvents = collect();
                $otherEvents = $threadEvents
                    ->reject(fn ($event) => $event->event_type === $mainEventType)
                    ->values();
                
                if ($otherEvents->isNotEmpty()) {
                    $currentGroup = null;
                    
                    foreach ($otherEvents as $event) {
                        if (!$currentGroup || $currentGroup->event_type !== $event->event_type) {
                            // Start a new group
                            if ($currentGroup) {
                                $groupRecipientEmails = $currentGroup->grouped_events
                                    ->pluck('recipient_emails')
                                    ->flatten()
                                    ->filter()
                                    ->reject($shouldIgnoreRecipient)
                                    ->unique()
                                    ->values()
                                    ->all();

                                $currentGroup->recipient_users = collect($groupRecipientEmails)
                                    ->map(fn ($email) => $usersByEmail->get($email))
                                    ->filter()
                                    ->values();
                                $currentGroup->all_recipient_emails = $groupRecipientEmails;

                                $groupedEvents->push($currentGroup);
                            }
                            $currentGroup = clone $event;
                            $currentGroup->grouped_count = 1;
                            $currentGroup->grouped_events = collect([$event]);
                        } else {
                            // Add to existing group
                            $currentGroup->grouped_count++;
                            $currentGroup->grouped_events->push($event);
                        }
                    }
                    
                    // Add the last group
                    if ($currentGroup) {
                        $groupRecipientEmails = $currentGroup->grouped_events
                            ->pluck('recipient_emails')
                            ->flatten()
                            ->filter()
                            ->reject($shouldIgnoreRecipient)
                            ->unique()
                            ->values()
                            ->all();

                        $currentGroup->recipient_users = collect($groupRecipientEmails)
                            ->map(fn ($email) => $usersByEmail->get($email))
                            ->filter()
                            ->values();
                        $currentGroup->all_recipient_emails = $groupRecipientEmails;

                        $groupedEvents->push($currentGroup);
                    }
                }
                
                $mainEvent->thread_events = $groupedEvents;
                
                return $mainEvent;
            })
            ->values()
            ->sortByDesc('event_at')
            ->values();


        return $events;
    }

    #[Title('Project')]
    public function render()
    {
        $this->authorize('view', $this->project);
        return view('livewire.projects.show');
    }
}
