<?php

namespace App\Jobs;

use App\Models\CompanyEmail;
use App\Models\Estimate;
use App\Models\User;
use App\Services\NylasService;
use App\Support\EstimateDocumentGenerator;
use App\Support\ProjectDocumentGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendEstimateEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $estimateId,
        protected int $companyEmailId,
        protected int $userId,
        protected array $recipients,
        protected string $subject,
        protected string $body,
        protected bool $includeEstimatePdf,
        protected bool $includeReimbursementsPdf
    ) {
    }

    public function handle(NylasService $nylasService): void
    {
        $estimate = Estimate::withoutGlobalScopes()->find($this->estimateId);
        if (! $estimate) {
            Log::channel('nylas')->warning('SendEstimateEmailJob skipped missing estimate', [
                'estimate_id' => $this->estimateId,
            ]);
            return;
        }

        /** @var User|null $user */
        $user = User::find($this->userId);
        if (! $user || ! $user->vendor) {
            Log::channel('nylas')->warning('SendEstimateEmailJob missing user or vendor', [
                'user_id' => $this->userId,
            ]);
            return;
        }

        $companyEmail = CompanyEmail::withoutGlobalScopes()->find($this->companyEmailId);
        if (! $companyEmail || ! $companyEmail->grant_id) {
            Log::channel('nylas')->warning('SendEstimateEmailJob missing company email grant', [
                'company_email_id' => $this->companyEmailId,
            ]);
            return;
        }

        if (! $estimate->vendor) {
            $estimate->setRelation('vendor', $user->vendor);
        }

        $attachments = [];

        try {
            if ($this->includeEstimatePdf) {
                $estimateDocument = EstimateDocumentGenerator::generate($estimate, 'Estimate');
                $formatted = $this->formatAttachment($estimateDocument);
                if ($formatted) {
                    $attachments[] = $formatted;
                }
            }

            if ($this->includeReimbursementsPdf) {
                if ($estimate->project) {
                    $reimbursementsDocument = ProjectDocumentGenerator::generateReimbursements($estimate->project);
                    $formatted = $this->formatAttachment($reimbursementsDocument);
                    if ($formatted) {
                        $attachments[] = $formatted;
                    }
                } else {
                    Log::channel('nylas')->warning('SendEstimateEmailJob missing project for reimbursements', [
                        'estimate_id' => $this->estimateId,
                    ]);
                }
            }
        } catch (Throwable $exception) {
            Log::channel('nylas')->error('SendEstimateEmailJob attachment generation failed', [
                'estimate_id' => $this->estimateId,
                'company_email_id' => $this->companyEmailId,
                'error' => $exception->getMessage(),
            ]);
            return;
        }

        $fromName = $user->vendor->business_name
            ?? trim($user->first_name.' '.$user->last_name)
            ?: config('app.name');

        $sanitizedRecipients = collect($this->recipients)
            ->map(fn ($email) => trim($email))
            ->filter()
            ->unique()
            ->values()
            ->map(fn ($email) => ['email' => $email])
            ->all();

        if (app()->environment('local', 'development')) {
            Log::channel('nylas')->info('SendEstimateEmailJob overriding recipients for development environment', [
                'estimate_id' => $this->estimateId,
                'company_email_id' => $this->companyEmailId,
                'original_recipients' => $sanitizedRecipients,
            ]);

            $sanitizedRecipients = [
                ['email' => 'patryk.szady@live.com'],
            ];
        }

        if (empty($sanitizedRecipients)) {
            Log::channel('nylas')->warning('SendEstimateEmailJob missing recipients after sanitization', [
                'estimate_id' => $this->estimateId,
                'company_email_id' => $this->companyEmailId,
            ]);

            return;
        }

        $to = $sanitizedRecipients;

        $payload = [
            'to' => $to,
            'from' => [[
                'email' => $companyEmail->email,
                'name' => $fromName,
            ]],
            'subject' => $this->subject,
            'body' => $this->body,
        ];

        if (! empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        $response = $nylasService->sendEmail($companyEmail->grant_id, $payload);

        if (! ($response['success'] ?? false)) {
            Log::channel('nylas')->error('SendEstimateEmailJob failed to send email', [
                'estimate_id' => $this->estimateId,
                'company_email_id' => $this->companyEmailId,
                'recipients' => $this->recipients,
            ]);
        }
    }

    private function formatAttachment(?array $document): ?array
    {
        if (! $document || empty($document['binary'])) {
            return null;
        }

        return [
            'filename' => $document['filename'] ?? 'document.pdf',
            'content_type' => 'application/pdf',
            'content' => base64_encode($document['binary']),
        ];
    }
}
