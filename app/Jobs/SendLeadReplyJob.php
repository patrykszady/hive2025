<?php

namespace App\Jobs;

use App\Mail\LeadReplyMail;
use App\Models\CompanyEmail;
use App\Models\Lead;
use App\Models\User;
use App\Services\NylasService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SendLeadReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $leadId,
        protected int $companyEmailId,
        protected int $userId,
        protected array $recipients,
        protected string $fromEmail,
        protected string $subject,
        protected string $body,
        protected ?string $emailTemplateName = null,
        protected ?string $senderIp = null,
        protected ?string $inReplyToMessageId = null,
        protected array $references = [],
    ) {
    }

    public function handle(NylasService $nylas): void
    {
        $trackingProvider = (string) config('email_tracking.provider', 'nylas');

        $lead = Lead::withoutGlobalScopes()->find($this->leadId);
        if (! $lead) {
            Log::warning('SendLeadReplyJob skipped missing lead', [
                'lead_id' => $this->leadId,
            ]);
            return;
        }

        $user = User::find($this->userId);
        if (! $user || ! $user->vendor) {
            Log::warning('SendLeadReplyJob missing user or vendor', [
                'user_id' => $this->userId,
            ]);
            return;
        }

        $companyEmail = CompanyEmail::find($this->companyEmailId);
        if (! $companyEmail) {
            Log::warning('SendLeadReplyJob missing company email', [
                'company_email_id' => $this->companyEmailId,
            ]);
            return;
        }

        if ($trackingProvider !== 'mailtrap' && ! $companyEmail->grant_id) {
            Log::warning('SendLeadReplyJob missing grant_id for Nylas', [
                'company_email_id' => $this->companyEmailId,
            ]);
            return;
        }

        $sanitizedRecipients = collect($this->recipients)
            ->map(fn ($email) => trim((string) $email))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($sanitizedRecipients)) {
            Log::warning('SendLeadReplyJob missing recipients', [
                'lead_id' => $this->leadId,
            ]);
            return;
        }

        try {
            $trackingId = (string) Str::uuid();

            $fromEmail = $this->fromEmail;
            if ($trackingProvider === 'mailtrap') {
                $safeFromEmail = (string) config('mail.from.address');
                if ($safeFromEmail !== '') {
                    $fromEmail = $safeFromEmail;
                }
            }

            if ($trackingProvider === 'mailtrap') {
                $mailer = (string) config('email_tracking.mailtrap_mailer', 'mailtrap-sdk');
            } else {
                $mailer = 'nylas';
                config(['mail.mailers.nylas.grant_id' => $companyEmail->grant_id]);
            }

            $mailable = new LeadReplyMail(
                lead: $lead,
                user: $user,
                fromEmail: $fromEmail,
                replyToEmail: $this->fromEmail,
                emailSubject: $this->subject,
                emailBody: $this->body,
                emailTemplateName: $this->emailTemplateName,
                senderIp: $this->senderIp,
                trackingId: $trackingId,
                inReplyTo: $this->inReplyToMessageId,
                references: $this->references,
            );

            if ($trackingProvider === 'mailtrap') {
                $mailable->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message) use ($trackingId, $lead): void {
                    $headers = $message->getHeaders();

                    $headers->add(new \Mailtrap\EmailHeader\CategoryHeader('lead_reply'));
                    $headers->add(new \Mailtrap\EmailHeader\CustomVariableHeader('tracking_id', $trackingId));
                    $headers->add(new \Mailtrap\EmailHeader\CustomVariableHeader('lead_id', (string) $lead->id));

                    if (is_string($this->emailTemplateName) && $this->emailTemplateName !== '') {
                        $headers->add(new \Mailtrap\EmailHeader\CustomVariableHeader('email_template_name', $this->emailTemplateName));
                    }
                });
            }

            Mail::mailer($mailer)
                ->to($sanitizedRecipients)
                ->cc($user->vendor->business_email)
                ->send($mailable);
        } catch (Throwable $exception) {
            Log::error('SendLeadReplyJob failed to send email', [
                'lead_id' => $this->leadId,
                'recipients' => $this->recipients,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
