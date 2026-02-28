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

class TaskReminderEmail extends Mailable
{
    use Queueable, SerializesModels;

    public string $recipientName;

    public string $contractorName;

    public string $taskTitle;

    public string $projectAddress;

    public string $projectName;

    public string $projectUrl;

    public string $clientLastNames;

    public string $registerUrl;

    public function __construct(
        public Task $task,
        string $recipientName = '',
    ) {
        $this->theme = 'transparent';

        $this->recipientName = $recipientName;
        $this->taskTitle = $task->title;

        $project = $task->project;
        $this->projectAddress = $project?->address ?? '';
        $this->projectName = $project?->project_name ?? '';
        $this->projectUrl = $project ? route('projects.show', $project) : route('dashboard');

        $vendor = Vendor::withoutGlobalScopes()->find($task->belongs_to_vendor_id);
        $this->contractorName = $vendor?->short_name ?? 'Your contractor';

        $client = $project?->client;
        $this->clientLastNames = $client?->last_names ?? '';

        $vendorId = $task->belongs_to_vendor_id;
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
        $subjectParts = array_filter([
            $this->contractorName,
            $this->clientLastNames,
            'New Task: ' . $this->taskTitle,
        ]);

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: implode(' | ', $subjectParts),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.task_reminder',
        );
    }
}
