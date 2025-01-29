<?php

namespace App\Mail;

use App\Models\Estimate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InitialEstimate extends Mailable
{
    use Queueable, SerializesModels;

    public $estimate;

    public $sections;

    public $type;

    /**
     * Create a new message instance.
     */
    public function __construct(Estimate $estimate, $sections, $type)
    {
        $this->estimate = $estimate;
        $this->sections = $sections;
        $this->type = $type;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('support@hive.contractors', 'Hive Contractors'),
            subject: $this->estimate->vendor->name.' | '.$this->estimate->client->name.' | Estimate',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.initial_estimate',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $data = app(\App\Livewire\Estimates\EstimateShow::class)
            ->create_pdf(
                $this->estimate,
                $this->sections,
                $this->type
            );

        return [
            //$data[0] = location
            Attachment::fromPath($data[0]),
        ];
    }
}
