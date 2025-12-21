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

        // Final pass: collapse consecutive opened rows (same template) into a single row.
        $collapsed = collect();
        $current = null;

        foreach ($events as $event) {
            if (! $current) {
                $current = $event;
                continue;
            }

            $canCollapseOpened = ($current->event_type === 'opened')
                && ($event->event_type === 'opened')
                && ((int) $current->project_id === (int) $event->project_id)
                && ((string) $current->email_template_name === (string) $event->email_template_name);

            if (! $canCollapseOpened) {
                $collapsed->push($current);
                $current = $event;
                continue;
            }

            $currentCount = (int) ($current->event_count ?? 1);
            $eventCount = (int) ($event->event_count ?? 1);
            $current->event_count = $currentCount + $eventCount;

            $mergedEmails = collect(array_merge(
                is_array($current->all_recipient_emails ?? null) ? $current->all_recipient_emails : [],
                is_array($event->all_recipient_emails ?? null) ? $event->all_recipient_emails : [],
            ))
                ->filter(fn ($email) => is_string($email) && $email !== '')
                ->unique()
                ->values()
                ->all();

            $current->all_recipient_emails = $mergedEmails;

            $mergedUsers = collect();
            foreach ([$current->recipient_users ?? null, $event->recipient_users ?? null] as $users) {
                if (! $users || ! $users instanceof \Illuminate\Support\Collection) {
                    continue;
                }

                foreach ($users as $user) {
                    if (! $user || ! isset($user->email) || ! is_string($user->email)) {
                        continue;
                    }

                    $mergedUsers->put($user->email, $user);
                }
            }

            $current->recipient_users = $mergedUsers->values();

            // Preserve and merge sub-rows (Delivered/Sent/etc) from both groups.
            $currentThreadEvents = $current->thread_events ?? collect();
            $eventThreadEvents = $event->thread_events ?? collect();

            $mergedThreadEvents = collect();
            foreach ([$currentThreadEvents, $eventThreadEvents] as $threadEvents) {
                if (! $threadEvents || ! $threadEvents instanceof \Illuminate\Support\Collection) {
                    continue;
                }

                foreach ($threadEvents as $subEvent) {
                    if ($subEvent) {
                        $mergedThreadEvents->push($subEvent);
                    }
                }
            }

            if ($mergedThreadEvents->isNotEmpty()) {
                $mergedThreadEvents = $mergedThreadEvents
                    ->sortByDesc('event_at')
                    ->values();

                $regrouped = collect();
                $group = null;

                foreach ($mergedThreadEvents as $subEvent) {
                    $subCount = (int) ($subEvent->grouped_count ?? 1);

                    if (! $group || $group->event_type !== $subEvent->event_type) {
                        if ($group) {
                            $regrouped->push($group);
                        }

                        $group = clone $subEvent;
                        $group->grouped_count = $subCount;

                        $groupEmails = collect(is_array($subEvent->all_recipient_emails ?? null) ? $subEvent->all_recipient_emails : [])
                            ->filter(fn ($email) => is_string($email) && $email !== '')
                            ->unique()
                            ->values()
                            ->all();
                        $group->all_recipient_emails = $groupEmails;
                    } else {
                        $group->grouped_count = (int) ($group->grouped_count ?? 1) + $subCount;

                        $group->all_recipient_emails = collect(array_merge(
                            is_array($group->all_recipient_emails ?? null) ? $group->all_recipient_emails : [],
                            is_array($subEvent->all_recipient_emails ?? null) ? $subEvent->all_recipient_emails : [],
                        ))
                            ->filter(fn ($email) => is_string($email) && $email !== '')
                            ->unique()
                            ->values()
                            ->all();

                        $mergedSubUsers = collect();
                        foreach ([$group->recipient_users ?? null, $subEvent->recipient_users ?? null] as $users) {
                            if (! $users || ! $users instanceof \Illuminate\Support\Collection) {
                                continue;
                            }

                            foreach ($users as $user) {
                                if (! $user || ! isset($user->email) || ! is_string($user->email)) {
                                    continue;
                                }

                                $mergedSubUsers->put($user->email, $user);
                            }
                        }
                        $group->recipient_users = $mergedSubUsers->values();
                    }
                }

                if ($group) {
                    $regrouped->push($group);
                }

                $current->thread_events = $regrouped;
            }
        }

        if ($current) {
            $collapsed->push($current);
        }

        $events = $collapsed;
        
        return $events;
    }

    #[Title('Project')]
    public function render()
    {
        $this->authorize('view', $this->project);
        return view('livewire.projects.show');
    }
}
