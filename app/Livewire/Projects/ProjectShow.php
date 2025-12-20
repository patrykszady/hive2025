<?php

namespace App\Livewire\Projects;

use App\Models\EmailTracking;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

class ProjectShow extends Component
{
    use AuthorizesRequests;
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
            return $event->nylas_thread_id ?: $event->nylas_message_id;
        })
            ->map(function ($threadEvents) use ($usersByEmail) {
                // Prioritize 'replied' as the main event, even if not the latest chronologically
                $repliedEvent = $threadEvents->firstWhere('event_type', 'replied');
                $mainEvent = $repliedEvent ?? $threadEvents->first();
                
                // Get all unique recipient emails from all events in this thread
                $threadRecipientEmails = $threadEvents
                    ->pluck('recipient_emails')
                    ->flatten()
                    ->unique()
                    ->values()
                    ->all();

                // Prefer showing the recipients for the specific event row (e.g. opened should be 1 recipient)
                $eventRecipientEmails = collect($mainEvent->recipient_emails ?? [])
                    ->flatten()
                    ->filter()
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
                
                // Group consecutive events of the same type (excluding the main event)
                $groupedEvents = collect();
                $otherEvents = $threadEvents->slice(1);
                
                if ($otherEvents->isNotEmpty()) {
                    $currentGroup = null;
                    
                    foreach ($otherEvents as $event) {
                        if (!$currentGroup || $currentGroup->event_type !== $event->event_type) {
                            // Start a new group
                            if ($currentGroup) {
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
                        $groupedEvents->push($currentGroup);
                    }
                }
                
                $mainEvent->thread_events = $groupedEvents;
                
                return $mainEvent;
            })
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
