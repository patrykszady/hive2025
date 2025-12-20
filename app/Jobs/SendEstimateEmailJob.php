<?php

namespace App\Jobs;

use App\Mail\EstimateMail;
use App\Models\CompanyEmail;
use App\Models\Estimate;
use App\Models\EmailTracking;
use App\Models\User;
use App\Support\EstimateDocumentGenerator;
use App\Support\ProjectDocumentGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SendEstimateEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $estimateId,
        protected int $companyEmailId, // Keep for backwards compatibility, but won't use
        protected int $userId,
        protected array $recipients,
        protected string $fromEmail,
        protected string $subject,
        protected string $body,
        protected bool $includeEstimatePdf,
        protected bool $includeReimbursementsPdf,
        protected ?string $emailTemplateName = null,
        protected ?string $senderIp = null,
    ) {
    }

    public function handle(): void
    {
        $trackingProvider = (string) config('email_tracking.provider', 'nylas');

        $estimate = Estimate::withoutGlobalScopes()->find($this->estimateId);
        if (! $estimate) {
            Log::warning('SendEstimateEmailJob skipped missing estimate', [
                'estimate_id' => $this->estimateId,
            ]);
            return;
        }

        /** @var User|null $user */
        $user = User::find($this->userId);
        if (! $user || ! $user->vendor) {
            Log::warning('SendEstimateEmailJob missing user or vendor', [
                'user_id' => $this->userId,
            ]);
            return;
        }

        $companyEmail = CompanyEmail::find($this->companyEmailId);
        if (! $companyEmail) {
            Log::warning('SendEstimateEmailJob missing company email', [
                'company_email_id' => $this->companyEmailId,
            ]);
            return;
        }

        if ($trackingProvider !== 'mailtrap' && ! $companyEmail->grant_id) {
            Log::warning('SendEstimateEmailJob missing grant_id for Nylas', [
                'company_email_id' => $this->companyEmailId,
            ]);
            return;
        }

        if (! $estimate->vendor) {
            $estimate->setRelation('vendor', $user->vendor);
        }

        $attachmentPaths = [];
        $tempFiles = [];

        try {
            // Ensure temp directory exists
            $tempDir = storage_path('app/temp');
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            if ($this->includeEstimatePdf) {
                $estimateDocument = EstimateDocumentGenerator::generate($estimate, 'Estimate');
                if ($estimateDocument && isset($estimateDocument['binary'], $estimateDocument['filename'])) {
                    $tempPath = $tempDir . '/' . $estimateDocument['filename'];
                    file_put_contents($tempPath, $estimateDocument['binary']);
                    $attachmentPaths[] = $tempPath;
                    $tempFiles[] = $tempPath;
                }
            }

            if ($this->includeReimbursementsPdf) {
                if ($estimate->project) {
                    $reimbursementsDocument = ProjectDocumentGenerator::generateReimbursements($estimate->project);
                    if ($reimbursementsDocument && isset($reimbursementsDocument['binary'], $reimbursementsDocument['filename'])) {
                        $tempPath = $tempDir . '/' . $reimbursementsDocument['filename'];
                        file_put_contents($tempPath, $reimbursementsDocument['binary']);
                        $attachmentPaths[] = $tempPath;
                        $tempFiles[] = $tempPath;
                    }
                } else {
                    Log::warning('SendEstimateEmailJob missing project for reimbursements', [
                        'estimate_id' => $this->estimateId,
                    ]);
                }
            }
        } catch (Throwable $exception) {
            Log::error('SendEstimateEmailJob attachment generation failed', [
                'estimate_id' => $this->estimateId,
                'error' => $exception->getMessage(),
            ]);
            return;
        }

        $sanitizedRecipients = collect($this->recipients)
            ->map(fn ($email) => trim($email))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (app()->environment('local', 'development')) {
            $devEmail = (string) config('mail.dev_email');
            $sanitizedRecipients = $devEmail !== '' ? [$devEmail] : ['patryk.szady@live.com'];
        }

        if (empty($sanitizedRecipients)) {
            Log::warning('SendEstimateEmailJob missing recipients after sanitization', [
                'estimate_id' => $this->estimateId,
            ]);

            // Clean up temp files
            foreach ($tempFiles as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            return;
        }

        try {
            $trackingId = (string) Str::uuid();

            $replyToEmail = $this->fromEmail;

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
                // Configure the Nylas mailer with this specific grant_id
                config(['mail.mailers.nylas.grant_id' => $companyEmail->grant_id]);
            }

            $mailable = new EstimateMail(
                estimate: $estimate,
                user: $user,
                fromEmail: $fromEmail,
                replyToEmail: $replyToEmail,
                emailSubject: $this->subject,
                emailBody: $this->body,
                attachmentPaths: $attachmentPaths,
                emailTemplateName: $this->emailTemplateName,
                senderIp: $this->senderIp,
                trackingId: $trackingId,
            );

            if ($trackingProvider === 'mailtrap') {
                $mailable->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message) use ($trackingId, $estimate): void {
                    $headers = $message->getHeaders();

                    $headers->add(new \Mailtrap\EmailHeader\CategoryHeader('estimate'));

                    $headers->add(new \Mailtrap\EmailHeader\CustomVariableHeader('tracking_id', $trackingId));

                    if ($estimate->project_id) {
                        $headers->add(new \Mailtrap\EmailHeader\CustomVariableHeader('project_id', (string) $estimate->project_id));
                    }

                    $headers->add(new \Mailtrap\EmailHeader\CustomVariableHeader('estimate_id', (string) $estimate->id));

                    if (is_string($this->emailTemplateName) && $this->emailTemplateName !== '') {
                        $headers->add(new \Mailtrap\EmailHeader\CustomVariableHeader('email_template_name', $this->emailTemplateName));
                    }
                });
            }

            Mail::mailer($mailer)
                ->to($sanitizedRecipients)
                ->send($mailable);

        } catch (Throwable $exception) {
            $mailerForLog = $mailer ?? null;
            $mailtrapKey = (string) (config('services.mailtrap-sdk.apiKey') ?? '');
            $mailtrapKeyLen = strlen($mailtrapKey);
            $mailtrapKeyFingerprint = $mailtrapKeyLen >= 12
                ? (substr($mailtrapKey, 0, 8).'...'.substr($mailtrapKey, -4))
                : null;

            Log::error('SendEstimateEmailJob failed to send email', [
                'estimate_id' => $this->estimateId,
                'recipients' => $this->recipients,
                'error' => $exception->getMessage(),
                'mailer' => $mailerForLog,
                'mailtrap_host' => config('services.mailtrap-sdk.host'),
                'mailtrap_api_key_len' => $mailtrapKeyLen,
                'mailtrap_api_key_fingerprint' => $mailtrapKeyFingerprint,
            ]);
        } finally {
            // Clean up temp files
            foreach ($tempFiles as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
