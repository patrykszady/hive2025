<?php

namespace App\Livewire\Projects;

use App\Models\Client;
use App\Models\EmailTracking;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

class ProjectsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    public $project_name_search = '';
    public $clients = [];
    public $client_id = '';

    public $client = null;

    // Store selected status codes from filter (as int); default to Active (6)
    public $project_status_title = [6];

    public $view = null;

    protected $queryString = [
        'project_name_search' => ['except' => ''],
        'client_id' => ['except' => ''],
        'project_status_title' => ['except' => [6]],
    ];

    public function mount()
    {
        if ($this->client) {
            $this->client_id = $this->client->id;
        } else {
            $this->clients = Client::orderBy('created_at', 'DESC')->get();
        }

        // Special case for view mode
        if ($this->view == true) {
            $this->project_status_title = [];
            return;
        }
        
        // Check URL parameters first
        if (request()->has('project_status_title')) {
            // Ensure it's an array
            if (!is_array($this->project_status_title)) {
                $this->project_status_title = [$this->project_status_title];
            }
            // Cast to int codes and filter out invalid codes (0, 9)
            $validCodes = [1, 2, 3, 4, 5, 6, 7, 8, 10, 11];
            $this->project_status_title = array_values(
                array_filter(
                    array_map('intval', $this->project_status_title),
                    fn($code) => in_array($code, $validCodes)
                )
            );
            // If URL parameter exists, store it in session
            Session::put('projects.status', $this->project_status_title);
        }
        // No URL parameter, but we have session value
        elseif (($sessionStatus = Session::get('projects.status')) && $sessionStatus !== [6]) {
            // Use session value, cast to int, and filter invalid codes
            $validCodes = [1, 2, 3, 4, 5, 6, 7, 8, 10, 11];
            $sessionStatus = is_array($sessionStatus) ? $sessionStatus : [$sessionStatus];
            $this->project_status_title = array_values(
                array_filter(
                    array_map('intval', $sessionStatus),
                    fn($code) => in_array($code, $validCodes)
                )
            );
        }
    }

    public function updating($field)
    {
        $this->resetPage();
    }

    public function updated($field)
    {
        if ($field === 'project_status_title') {
            // Always update session when status changes
            Session::put('projects.status', $this->project_status_title);
        }
        
        // Reset filters logic
        if ($field === 'client_id') {
            $this->project_status_title = [];
            Session::put('projects.status', []);
        }

        if ($field === 'project_name_search') {
            $this->project_status_title = [];
            $this->client_id = '';
            Session::put('projects.status', []);
        }
    }

    #[Computed]
    public function projects()
    {
        // Existing projects method unchanged
        if (! is_null($this->client)) {
            if (isset($this->client->vendor_id)) {
                //all clients(projects) with $client->vendor_id
                $client_ids = Project::where('belongs_to_vendor_id', $this->client->vendor_id)->pluck('client_id')->toArray();
            } else {
                $client_ids = [$this->client->id];
            }
        } else {
            $client_ids = [];
        }

        return Project::with(['latestStatus', 'client.users'])
            ->when(!empty($this->project_status_title), function ($query) {
                // Expand "Complete" (7) to also include "Service Call" (8) for backwards compatibility
                $codes = collect($this->project_status_title)
                    ->flatMap(function ($code) {
                        if ($code === 7) {
                            return [7, 8]; // Complete, Service Call
                        }
                        return [(int)$code];
                    })
                    ->unique()
                    ->values()
                    ->all();
                    
                $query->whereHas('latestStatus', function ($query) use ($codes) {
                    $query->whereIn('status_code', $codes);
                });
            })
            ->when($this->client !== null, function ($query) use ($client_ids) {
                $query->whereIn('client_id', $client_ids);
            })
            ->orderByLatestStatusDateDesc()
            ->paginate(20);
    }

    #[Computed]
    public function stats()
    {
        // Get base query
        $baseQuery = Project::query();
        
        if (! is_null($this->client)) {
            if (isset($this->client->vendor_id)) {
                $client_ids = Project::where('belongs_to_vendor_id', $this->client->vendor_id)->pluck('client_id')->toArray();
            } else {
                $client_ids = [$this->client->id];
            }
            $baseQuery->whereIn('client_id', $client_ids);
        }

        $projectIds = (clone $baseQuery)->pluck('id');

        $projects = (clone $baseQuery)->with('latestStatus')->get();

        $latestStatuses = $projects
            ->pluck('latestStatus.title') // accessor provides label
            ->filter()
            ->countBy();

        $projectStatuses = $projectIds->isEmpty()
            ? collect()
            : ProjectStatus::select('project_id', 'status_code', 'start_date', 'id')
                ->whereIn('project_id', $projectIds)
                ->orderBy('project_id')
                ->orderBy('start_date')
                ->orderBy('id')
                ->get()
                ->groupBy('project_id')
                ->map(function ($statuses) {
                    return $statuses
                        ->values()
                        ->map(function ($status) {
                            return [
                                'title' => ProjectStatus::getLabelForCode((int) $status->status_code),
                                'start_date' => $status->start_date
                                    ? $status->start_date->copy()
                                    : null,
                            ];
                        });
                });

        // Define stats in display order
        $stats = [
            [
                'title' => 'Active',
                'value' => (string) $latestStatuses->get('Active', 0),
                'chartData' => $this->getYtdChartData('Active', $projectStatuses),
            ],
            [
                'title' => 'Estimate',
                'value' => (string) $latestStatuses->get('Estimate', 0),
                'chartData' => $this->getYtdChartData('Estimate', $projectStatuses),
            ],
            [
                'title' => 'Response',
                'value' => (string) $latestStatuses->get('Awaiting Response', 0),
                'chartData' => $this->getYtdChartData('Awaiting Response', $projectStatuses),
            ],
            [
                'title' => 'Scheduled',
                'value' => (string) $latestStatuses->get('Scheduled', 0),
                'chartData' => $this->getYtdChartData('Scheduled', $projectStatuses),
            ],
        ];

        return $stats;
    }

    protected function getYtdChartData(string $status, Collection $projectStatuses): array
    {
        $now = now();
        $currentYear = $now->year;
        $currentMonth = $now->month;

        if ($projectStatuses->isEmpty()) {
            return array_fill(0, $currentMonth, 0);
        }

        $monthlyData = [];
        $pointers = [];

        for ($month = 1; $month <= $currentMonth; $month++) {
            $endOfMonth = $now->copy()->setDate($currentYear, $month, 1)->endOfMonth();
            $count = 0;

            foreach ($projectStatuses as $projectId => $statuses) {
                $index = $pointers[$projectId] ?? -1;
                $statusesCount = $statuses->count();

                while (($index + 1) < $statusesCount) {
                    $next = $statuses[$index + 1];
                    /** @var Carbon|null $startDate */
                    $startDate = $next['start_date'];

                    if ($startDate !== null && $startDate->gt($endOfMonth)) {
                        break;
                    }

                    $index++;
                }

                $pointers[$projectId] = $index;

                if ($index >= 0) {
                    $currentStatus = $statuses[$index]['title'];
                    if ($currentStatus === $status) {
                        $count++;
                    }
                }
            }

            $monthlyData[] = $count;
        }

        return $monthlyData;
    }

    #[Computed]
    public function emailTrackingEvents()
    {
        // Get all events grouped by message/thread.
        // For Mailtrap, group by mailtrap_message_id so multi-recipient opens collapse into one row.
        $allEvents = EmailTracking::with('project')
            ->when($this->client !== null, function ($query) {
                $query->whereHas('project', function ($q) {
                    $q->where('client_id', $this->client->id);
                });
            })
            ->when($this->client_id, function ($query) {
                $query->whereHas('project', function ($q) {
                    $q->where('client_id', $this->client_id);
                });
            })
            ->orderBy('event_at', 'DESC')
            ->get();

        $allEmails = $allEvents->pluck('recipient_emails')->flatten()->unique()->values()->all();
        $usersByEmail = User::query()->whereIn('email', $allEmails)->get()->keyBy('email');

        $sentCandidatesByProjectAndTemplate = $allEvents
            ->where('event_type', 'sent')
            ->groupBy(fn ($event) => (string) $event->project_id . '|' . (string) $event->email_template_name);

        /** @var array<int, int> $inferredSentIdByEventId */
        $inferredSentIdByEventId = [];
        $inferenceWindowSeconds = 6 * 60 * 60;

        foreach ($allEvents as $event) {
            if ($event->event_type === 'sent') {
                continue;
            }

            $linkedSentId = is_array($event->metadata) ? ($event->metadata['linked_sent_id'] ?? null) : null;
            if (is_numeric($linkedSentId) && (int) $linkedSentId > 0) {
                continue;
            }

            if (! $event->project_id || ! is_string($event->email_template_name) || $event->email_template_name === '') {
                continue;
            }

            if (! $event->event_at) {
                continue;
            }

            $recipientEmails = is_array($event->recipient_emails) ? $event->recipient_emails : [];
            $recipientEmails = collect($recipientEmails)->filter(fn ($email) => is_string($email) && $email !== '')->values()->all();
            if (empty($recipientEmails)) {
                continue;
            }

            $candidates = $sentCandidatesByProjectAndTemplate->get((string) $event->project_id . '|' . (string) $event->email_template_name, collect());
            if ($candidates->isEmpty()) {
                continue;
            }

            $best = $candidates
                ->filter(function ($sent) use ($recipientEmails, $event) {
                    if (! $sent->event_at) {
                        return false;
                    }

                    $sentRecipients = is_array($sent->recipient_emails) ? $sent->recipient_emails : [];
                    $hasRecipient = (bool) collect($recipientEmails)->first(fn ($email) => in_array($email, $sentRecipients, true));
                    if (! $hasRecipient) {
                        return false;
                    }

                    // Sent must be before (or equal) to the event.
                    return $sent->event_at->lte($event->event_at);
                })
                ->sortByDesc('event_at')
                ->first();

            if (! $best || ! $best->event_at) {
                continue;
            }

            if ($event->event_at->diffInSeconds($best->event_at) > $inferenceWindowSeconds) {
                continue;
            }

            $inferredSentIdByEventId[$event->id] = (int) $best->id;
        }

        $events = $allEvents
            ->groupBy(function ($event) use ($inferredSentIdByEventId) {
                // Group all per-recipient events (opened/delivered/etc) by their originating sent event.
                if ($event->event_type === 'sent') {
                    return 'sent:' . $event->id;
                }

                $linkedSentId = is_array($event->metadata) ? ($event->metadata['linked_sent_id'] ?? null) : null;
                if (is_numeric($linkedSentId) && (int) $linkedSentId > 0) {
                    return 'sent:' . (int) $linkedSentId;
                }

                $inferredSentId = $inferredSentIdByEventId[$event->id] ?? null;
                if (is_int($inferredSentId) && $inferredSentId > 0) {
                    return 'sent:' . $inferredSentId;
                }

                // Fallback grouping.
                $mailtrapMessageId = is_array($event->metadata)
                    ? ($event->metadata['mailtrap_message_id'] ?? null)
                    : null;

                if (is_string($mailtrapMessageId) && $mailtrapMessageId !== '') {
                    return 'mailtrap:' . $mailtrapMessageId;
                }

                return $event->thread_id ?: $event->message_id ?: ('email_tracking:' . $event->id);
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

                $threadRecipientEmails = $threadEvents
                    ->pluck('recipient_emails')
                    ->flatten()
                    ->filter()
                    ->reject($shouldIgnoreRecipient)
                    ->unique()
                    ->values()
                    ->all();

                // Aggregate recipients across the same event type (e.g. opened) for this message.
                $mainEventType = (string) ($mainEvent->event_type ?? '');
                $eventRecipientEmails = $threadEvents
                    ->where('event_type', $mainEventType)
                    ->pluck('recipient_emails')
                    ->flatten()
                    ->filter()
                    ->reject($shouldIgnoreRecipient)
                    ->unique()
                    ->values()
                    ->all();

                $users = collect($eventRecipientEmails)
                    ->map(fn ($email) => $usersByEmail->get($email))
                    ->filter()
                    ->values();

                $eventCount = $threadEvents->where('event_type', $mainEventType)->count();

                $mainEvent->recipient_users = $users;
                $mainEvent->all_recipient_emails = ! empty($eventRecipientEmails) ? $eventRecipientEmails : $threadRecipientEmails;
                $mainEvent->event_count = $eventCount;

                return $mainEvent;
            })
            ->sortByDesc('event_at')
            ->values();

        // Final pass: if multiple opened rows are consecutive for the same project+template,
        // collapse them into a single row (Opened xN) with combined recipients.
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
        }

        if ($current) {
            $collapsed->push($current);
        }

        $events = $collapsed;
        
        // Manual pagination
        $currentPage = \Illuminate\Pagination\Paginator::resolveCurrentPage('page');
        $perPage = 10;
        $currentPageItems = $events->slice(($currentPage - 1) * $perPage, $perPage)->values();
        
        return new \Illuminate\Pagination\LengthAwarePaginator(
            $currentPageItems,
            $events->count(),
            $perPage,
            $currentPage,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    #[Title('Projects')]
    public function render()
    {
        $this->authorize('viewAny', Project::class);
        return view('livewire.projects.index');
    }
}