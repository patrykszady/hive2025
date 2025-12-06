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

    public function mount()
    {
        //include deleted
        $this->estimates = $this->project->estimates()->orderBy('created_at', 'DESC')->get();
    }

    #[Computed]
    public function emailTrackingEvents()
    {
        $events = EmailTracking::with('project')
            ->where('project_id', $this->project->id)
            ->orderBy('event_at', 'DESC')
            ->get()
            ->groupBy('nylas_thread_id')
            ->map(function ($threadEvents) {
                // Prioritize 'replied' as the main event, even if not the latest chronologically
                $repliedEvent = $threadEvents->firstWhere('event_type', 'replied');
                $mainEvent = $repliedEvent ?? $threadEvents->first();
                
                // Get all unique recipient emails from all events in this thread
                $allRecipientEmails = $threadEvents
                    ->pluck('recipient_emails')
                    ->flatten()
                    ->unique()
                    ->values()
                    ->all();
                
                // Map emails to users
                $users = collect($allRecipientEmails)
                    ->map(function ($email) {
                        return User::where('email', $email)->first();
                    })
                    ->filter()
                    ->values();
                
                // Add recipient user data to the main event
                $mainEvent->recipient_users = $users;
                $mainEvent->all_recipient_emails = $allRecipientEmails;
                
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
