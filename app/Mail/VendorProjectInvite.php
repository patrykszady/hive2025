<?php

namespace App\Mail;

use App\Models\Project;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VendorProjectInvite extends Mailable
{
    use Queueable, SerializesModels;

    public string $inviterName;

    public string $vendorName;

    public string $projectName;

    public ?string $projectAddress;

    public string $projectUrl;

    public ?string $recipientName;

    public ?string $customMessage;

    public function __construct(
        public Vendor $invitingVendor,
        public Vendor $invitedVendor,
        public Project $project,
        ?string $recipientName = null,
        ?string $customMessage = null,
    ) {
        $this->theme = 'transparent';

        $this->inviterName = $invitingVendor->short_name ?? $invitingVendor->name ?? 'A contractor';
        $this->vendorName = $invitedVendor->short_name ?? $invitedVendor->name ?? '';
        $this->projectName = $project->project_name ?? 'a project';
        $this->projectAddress = collect([
            $project->address,
            $project->address_2,
            trim(implode(' ', array_filter([
                $project->city,
                $project->state,
                $project->zip_code,
            ]))),
        ])->filter()->implode("\n");
        $this->recipientName = $recipientName;
        $this->customMessage = $customMessage;

        $this->projectUrl = route('projects.show', ['project' => $project->id]);

        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message): void {
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CategoryHeader('vendor_project_invite'));
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CustomVariableHeader('inviting_vendor_id', (string) $this->invitingVendor->id));
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CustomVariableHeader('invited_vendor_id', (string) $this->invitedVendor->id));
            $message->getHeaders()->add(new \Mailtrap\EmailHeader\CustomVariableHeader('project_id', (string) $this->project->id));
        });
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->inviterName . ' invited you to a project',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.vendor_project_invite',
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
