<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class TaskNotificationDigest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Grouped tasks keyed by date string (Y-m-d).
     *
     * @var Collection<string, Collection>
     */
    public Collection $groupedTasks;

    public User $user;

    public string $type;

    public string $headline;

    public string $userTz;

    public bool $isClientUser;

    /**
     * Create a new message instance.
     *
     * @param  string  $type  'morning'|'evening'|'update'
     */
    public function __construct(
        User $user,
        string $type = 'morning',
    ) {
        $this->theme = 'transparent';

        $this->user = $user;
        $this->type = $type;
        $this->userTz = $this->userTimezone();
        $this->isClientUser = (bool) $user->is_client_user;
        $this->groupedTasks = $this->buildNext7Days();

        $userNow = Carbon::now($this->userTimezone());

        // Zero-width non-joiner between date parts to prevent Outlook auto-linking
        $zwnj = "\u{200C}";
        $this->headline = match ($this->type) {
            'morning' => "Today's Tasks " . $userNow->format('m') . $zwnj . '/' . $userNow->format('d') . $zwnj . '/' . $userNow->format('y'),
            'evening' => "Tomorrow's Tasks " . $userNow->copy()->addDay()->format('m') . $zwnj . '/' . $userNow->copy()->addDay()->format('d') . $zwnj . '/' . $userNow->copy()->addDay()->format('y'),
            'update'  => 'Schedule Updated',
            default   => 'Task Notification',
        };

        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message): void {
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CategoryHeader('task_notification'));
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CustomVariableHeader('notification_type', $this->type));
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CustomVariableHeader('user_id', (string) $this->user->id));
        });
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->headline,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.task_notification_digest',
        );
    }

    /**
     * Get the message headers.
     */
    public function headers(): Headers
    {
        return new Headers(text: [
            'X-Email-Metadata' => json_encode([
                'email_type' => 'task_notification',
                'notification_type' => $this->type,
                'user_id' => $this->user->id,
            ]),
        ]);
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    /**
     * Get the user's timezone from their vendor, or fall back to app timezone.
     */
    protected function userTimezone(): string
    {
        $vendorTz = $this->user->vendor?->timezone;

        return is_string($vendorTz) && $vendorTz !== ''
            ? $vendorTz
            : (string) config('app.timezone');
    }

    /**
     * Build a 7-day grouped task collection for this user.
     *
     * @return Collection<string, Collection>
     */
    protected function buildNext7Days(): Collection
    {
        $startDate = Carbon::today($this->userTimezone());
        $endDate = $startDate->copy()->addDays(6);

        // Initialise every day in the range so empty days still show
        $grouped = collect();
        for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {
            $grouped[$d->format('Y-m-d')] = collect();
        }

        $tasksQuery = Task::query()
            ->with(['project.client', 'project.latestStatus', 'vendor'])
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                  ->orWhereBetween('end_date', [$startDate, $endDate])
                  ->orWhere(function ($q2) use ($startDate, $endDate) {
                      $q2->where('start_date', '<=', $startDate)
                         ->where('end_date', '>=', $endDate);
                  });
            });

        if ($this->isClientUser) {
            $clientIds = $this->user->clients()
                ->withoutGlobalScopes()
                ->pluck('clients.id');

            if ($clientIds->isEmpty()) {
                return $grouped;
            }

            $projectIds = Project::query()
                ->withoutGlobalScopes()
                ->whereIn('client_id', $clientIds)
                ->pluck('id');

            if ($projectIds->isEmpty()) {
                return $grouped;
            }

            $tasksQuery->whereIn('project_id', $projectIds);
        } else {
            $tasksQuery->whereJsonContains('user_ids', (string) $this->user->id);
        }

        $tasks = $tasksQuery
            ->orderBy('start_date')
            ->get();

        foreach ($tasks as $task) {
            $dates = data_get($task->options, 'dates', []);
            if (empty($dates) && $task->start_date) {
                $dates = [$task->start_date->format('Y-m-d')];
            }

            foreach ($dates as $date) {
                if ($grouped->has($date)) {
                    $grouped[$date]->push($task);
                }
            }
        }

        return $grouped;
    }
}
