<?php

namespace App\Mail;

use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class PendingTasksReminderEmail extends Mailable
{
    use Queueable, SerializesModels;

    public string $recipientName;

    public string $contractorName;

    public string $projectAddress;

    public string $projectName;

    public string $projectUrl;

    public string $clientLastNames;

    public string $registerUrl;

    /** @var Collection<int, Task> */
    public Collection $tasks;

    public function __construct(
        Collection $tasks,
        public Project $project,
        string $recipientName = '',
    ) {
        $this->theme = 'transparent';

        $this->tasks = $tasks;
        $this->recipientName = $recipientName;
        $this->projectAddress = $project->address ?? '';
        $this->projectName = $project->project_name ?? '';
        $this->projectUrl = route('projects.show', $project);

        $vendor = Vendor::withoutGlobalScopes()->find($project->belongs_to_vendor_id);
        $this->contractorName = $vendor?->short_name ?? 'Your contractor';

        $client = $project->client;
        $this->clientLastNames = $client?->last_names ?? '';

        $vendorId = $project->belongs_to_vendor_id;
        $this->registerUrl = rtrim((string) config('app.url'), '/') . '/invite/' . base64_encode(json_encode([
            'invite' => 'client',
            'vid' => $vendorId,
        ]));

        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message): void {
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CategoryHeader('task_reminder'));
        });
    }

    public function envelope(): Envelope
    {
        $taskCount = $this->tasks->count();
        $taskLabel = $taskCount === 1
            ? 'New Task: ' . $this->tasks->first()->title
            : $taskCount . ' New Tasks';

        $subjectParts = array_filter([
            $this->contractorName,
            $this->clientLastNames,
            $taskLabel,
        ]);

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: implode(' | ', $subjectParts),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.pending_tasks_reminder',
        );
    }
}
