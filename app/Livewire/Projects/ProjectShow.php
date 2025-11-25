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
                // Get the first (latest) event as the main row
                $mainEvent = $threadEvents->first();
                
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
                
                // Store all thread events for sub-rows (excluding the first one)
                $mainEvent->thread_events = $threadEvents->slice(1);
                
                return $mainEvent;
            })
            ->values();
        
        // Manual pagination
        $currentPage = \Illuminate\Pagination\Paginator::resolveCurrentPage('page');
        $perPage = 10;
        $currentPageItems = $events->slice(($currentPage - 1) * $perPage, $perPage)->values();
        
        return new LengthAwarePaginator(
            $currentPageItems,
            $events->count(),
            $perPage,
            $currentPage,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    #[Title('Project')]
    public function render()
    {
        $this->authorize('view', $this->project);
        return view('livewire.projects.show');
    }
}
