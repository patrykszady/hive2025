<?php

namespace App\Jobs;

use App\Mail\ServiceCallScheduleMail;
use App\Models\CompanyEmail;
use App\Models\Project;
use App\Models\ServiceCallEmail;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ServiceCalls\ServiceCallEmailIntake;
use App\Support\EmailSignature;
use App\Support\MailActor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reply to the client's service-request email with the schedule page link,
 * from the company the same way SendLeadReplyJob answers a lead (Mailtrap
 * from the verified sender with the team as Reply-To, or Nylas from the
 * mailbox itself), plus the office copy.
 */
class SendServiceCallScheduleEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $serviceCallEmailId)
    {
    }

    public function handle(): void
    {
        $row = ServiceCallEmail::query()->find($this->serviceCallEmailId);

        if (! $row || ! $row->isOpened() || $row->emailed_at) {
            return;
        }

        $project = Project::withoutGlobalScopes()->find($row->project_id);
        $actor = User::withoutGlobalScopes()->find($row->actor_user_id);

        if (! $project || ! $actor) {
            return;
        }

        $vendor = Vendor::withoutGlobalScopes()->find($project->belongs_to_vendor_id);
        $vendorName = (string) ($vendor?->name ?: config('app.name'));
        $shortName = (string) (data_get($vendor?->options, 'short_name') ?: $vendor?->short_name ?: $vendorName);
        $recipients = collect([$row->from_email])
            ->merge((array) data_get($row->verdict, 'reply.cc', []))
            ->map(fn ($email) => trim((string) $email))
            ->filter()
            ->unique()
            ->values();

        $firstName = trim((string) ($row->user()->first()?->first_name ?? ''));
        $issue = (string) (data_get($row->verdict, 'issue') ?: 'this');
        $scheduleUrl = ServiceCallEmailIntake::scheduleUrl($project);
        $where = trim((string) $project->address);

        $body = '<p>Hi'.($firstName !== '' ? ' '.e($firstName) : '').',</p>'
            .'<p>Thanks for letting us know about '.e($issue).'. We\'ve opened a service call'.($where !== '' ? ' for '.e($where) : '').'.</p>'
            .'<p>Please pick the times that work for you, and we\'ll confirm the visit:</p>'
            .'<p><a href="'.e($scheduleUrl).'" style="display:inline-block;padding:10px 22px;background-color:#4f46e5;color:#ffffff;font-weight:600;text-decoration:none;border-radius:6px;">Choose times</a></p>'
            .'<p style="color:#71717a;font-size:13px;">Or open this link: <a href="'.e($scheduleUrl).'">'.e($scheduleUrl).'</a></p>'
            .EmailSignature::html($shortName);

        $originalSubject = trim((string) data_get($row->verdict, 'reply.subject', ''));
        $subject = $originalSubject !== ''
            ? 'Re: '.preg_replace('/^\s*(re|fwd?|fw)\s*:\s*/i', '', $originalSubject)
            : 'Let\'s schedule your service call — '.$shortName;

        // Written before the send: a crash after it must never email twice.
        $row->forceFill(['emailed_at' => now()])->save();

        try {
            MailActor::as($actor);
            $provider = (string) config('email_tracking.provider', 'nylas');
            $trackingId = (string) Str::uuid();
            $businessEmail = trim((string) $vendor?->business_email);

            if ($provider === 'mailtrap') {
                $mailer = (string) config('email_tracking.mailtrap_mailer', 'mailtrap-sdk');
                $fromEmail = (string) config('mail.from.address');
            } else {
                $companyEmail = CompanyEmail::withoutGlobalScopes()
                    ->where('vendor_id', $project->belongs_to_vendor_id)
                    ->whereNotNull('grant_id')
                    ->when($row->mailbox, fn ($query) => $query->orderByRaw('LOWER(email) = ? DESC', [$row->mailbox]))
                    ->orderBy('id')
                    ->first();

                if (! $companyEmail) {
                    Log::warning('SendServiceCallScheduleEmail: no connected mailbox to send from', ['service_call_email_id' => $row->id]);

                    return;
                }

                $mailer = 'nylas';
                $fromEmail = (string) $companyEmail->email;
                config(['mail.mailers.nylas.grant_id' => $companyEmail->grant_id]);
            }

            $mail = fn (): ServiceCallScheduleMail => new ServiceCallScheduleMail(
                serviceCall: $row,
                actor: $actor,
                fromEmail: $fromEmail,
                fromName: $vendorName,
                replyToEmail: $businessEmail !== '' ? $businessEmail : $row->mailbox,
                emailSubject: $subject,
                emailBody: $body,
                inReplyTo: data_get($row->verdict, 'reply.rfc_message_id'),
                trackingId: $trackingId,
            );

            $message = $mail();

            if ($provider === 'mailtrap') {
                $message->withSymfonyMessage(function (\Symfony\Component\Mime\Email $email) use ($trackingId, $project): void {
                    $headers = $email->getHeaders();
                    $headers->add(new \Mailtrap\EmailHeader\CategoryHeader('service_call_schedule'));
                    $headers->add(new \Mailtrap\EmailHeader\CustomVariableHeader('tracking_id', $trackingId));
                    $headers->add(new \Mailtrap\EmailHeader\CustomVariableHeader('project_id', (string) $project->id));
                    $headers->add(new \Mailtrap\EmailHeader\CustomVariableHeader('belongs_to_vendor_id', (string) $project->belongs_to_vendor_id));
                    $headers->add(new \Mailtrap\EmailHeader\CustomVariableHeader('email_template_name', 'auto-service-call-schedule'));
                });
            }

            Mail::mailer($mailer)->to($recipients->all())->send($message);

            if ($businessEmail !== '') {
                try {
                    $copy = $mail();
                    $copy->withSymfonyMessage(function (\Symfony\Component\Mime\Email $email): void {
                        $email->getHeaders()->addTextHeader('X-Hive-Internal-Copy', 'true');
                    });
                    Mail::mailer($mailer)->to($businessEmail)->send($copy);
                } catch (Throwable $copyException) {
                    Log::warning('SendServiceCallScheduleEmail: office copy failed', ['service_call_email_id' => $row->id, 'error' => $copyException->getMessage()]);
                }
            }
        } catch (Throwable $exception) {
            Log::error('SendServiceCallScheduleEmail failed', ['service_call_email_id' => $row->id, 'error' => $exception->getMessage()]);
        } finally {
            MailActor::forget();
        }
    }
}
