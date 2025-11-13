<?php

namespace App\Jobs;

use App\Mail\EstimateMail;
use App\Models\Estimate;
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
        protected bool $includeReimbursementsPdf
    ) {
    }

    public function handle(): void
    {
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

        if (! $estimate->vendor) {
            $estimate->setRelation('vendor', $user->vendor);
        }

        $attachmentPaths = [];
        $tempFiles = [];

        try {
            if ($this->includeEstimatePdf) {
                $estimateDocument = EstimateDocumentGenerator::generate($estimate, 'Estimate');
                if ($estimateDocument && isset($estimateDocument['binary'], $estimateDocument['filename'])) {
                    $tempPath = storage_path('app/temp/' . $estimateDocument['filename']);
                    file_put_contents($tempPath, $estimateDocument['binary']);
                    $attachmentPaths[] = $tempPath;
                    $tempFiles[] = $tempPath;
                }
            }

            if ($this->includeReimbursementsPdf) {
                if ($estimate->project) {
                    $reimbursementsDocument = ProjectDocumentGenerator::generateReimbursements($estimate->project);
                    if ($reimbursementsDocument && isset($reimbursementsDocument['binary'], $reimbursementsDocument['filename'])) {
                        $tempPath = storage_path('app/temp/' . $reimbursementsDocument['filename']);
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
            $sanitizedRecipients = ['patryk.szady@live.com'];
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
            Mail::to($sanitizedRecipients)
                ->send(new EstimateMail(
                    estimate: $estimate,
                    user: $user,
                    fromEmail: $this->fromEmail,
                    emailSubject: $this->subject,
                    emailBody: $this->body,
                    attachmentPaths: $attachmentPaths
                ));
        } catch (Throwable $exception) {
            Log::error('SendEstimateEmailJob failed to send email', [
                'estimate_id' => $this->estimateId,
                'recipients' => $this->recipients,
                'error' => $exception->getMessage(),
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
