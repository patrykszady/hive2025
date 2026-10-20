<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\ServiceCallEmail;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use App\Services\ServiceCalls\ServiceCallEmailIntake;
use App\Services\UrlShortener;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Text the client who emailed the schedule page link for their new service
 * call, through the normal SMS pipeline and its opt-in rules — the same
 * thread choice LeadScheduleLinkTexter makes: their own thread when one
 * exists, else a new one (a new number gets the START prompt first).
 * Dispatched for business hours by ServiceCallEmailIntake.
 */
class TextServiceCallScheduleLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $serviceCallEmailId)
    {
    }

    public function handle(GroupSmsService $sms, UrlShortener $shortener): void
    {
        $row = ServiceCallEmail::query()->find($this->serviceCallEmailId);

        if (! $row || ! $row->isOpened() || $row->texted_at) {
            return;
        }

        $project = Project::withoutGlobalScopes()->find($row->project_id);
        $actor = User::withoutGlobalScopes()->find($row->actor_user_id);
        $client = User::withoutGlobalScopes()->find($row->user_id);

        if (! $project || ! $actor || ! $client) {
            return;
        }

        $digits = preg_replace('/\D/', '', (string) $client->cell_phone);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            Log::channel('client_sms')->info('TextServiceCallScheduleLink: client has no usable cell number', ['service_call_email_id' => $row->id]);

            return;
        }

        $e164 = GroupSmsService::formatE164($digits);
        $vendor = Vendor::withoutGlobalScopes()->find($project->belongs_to_vendor_id);
        $contractor = trim((string) (data_get($vendor?->options, 'short_name') ?: $vendor?->short_name ?: $vendor?->name)) ?: (string) config('app.name');
        $firstName = trim((string) $client->first_name);
        $issue = (string) (data_get($row->verdict, 'issue') ?: 'this');
        $link = $shortener->shorten(ServiceCallEmailIntake::scheduleUrl($project));

        $text = 'Hi'.($firstName !== '' ? " {$firstName}" : '').",\n\nThanks for letting us know about {$issue}. Pick times that work for your service call with {$contractor} here: {$link}";

        $thread = SmsGroupThread::query()
            ->accessibleTo($actor)
            ->whereJsonContains('participants', $e164)
            ->whereJsonLength('participants', 1)
            ->latest('last_activity_at')
            ->first();

        if ($thread && $thread->hasPendingOptIn()) {
            Log::channel('client_sms')->info('TextServiceCallScheduleLink: awaiting START reply, not texted', ['service_call_email_id' => $row->id]);

            return;
        }

        $row->forceFill(['texted_at' => now()])->save();

        if ($thread) {
            $sms->sendToThread($thread, $text, [], $actor->id);

            return;
        }

        $sms->sendNewGroup([$e164], $text, $project->id, $project->client_id, $actor->id, $actor->vendor?->id);
    }
}
