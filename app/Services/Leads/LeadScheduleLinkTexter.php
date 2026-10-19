<?php

namespace App\Services\Leads;

use App\Models\Client;
use App\Models\Lead;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ConsultScheduleLinkTexter;
use App\Services\GroupSmsService;
use App\Services\UrlShortener;

/**
 * Text a lead the signed "pick consultation times" link through the normal
 * SMS pipeline — the lead modal's "Need a different time?" send, factored
 * out so the Hive Contractors app's lead detail screen can trigger the
 * exact same send. See App\Livewire\Leads\LeadCreate::textScheduleLink(),
 * which now delegates here, and App\Services\ConsultScheduleLinkTexter for
 * the sibling flow that starts from a Messages thread instead of a lead.
 */
class LeadScheduleLinkTexter
{
    public function __construct(
        protected GroupSmsService $sms,
        protected UrlShortener $shortener,
    ) {
    }

    /**
     * @return array{ok: bool, variant: string, heading: string, text: string}
     */
    public function text(Lead $lead, ?Client $client, User $actor): array
    {
        $digits = preg_replace('/\D/', '', (string) ($lead->lead_data['phone'] ?? ''));
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return $this->result(false, 'warning', 'No phone number', 'Add a phone number to this lead first.');
        }

        $e164 = GroupSmsService::formatE164($digits);

        $vendor = Vendor::find($lead->belongs_to_vendor_id);
        $contractor = trim((string) (data_get($vendor?->options, 'short_name') ?: $vendor?->name)) ?: config('app.name');

        $thread = SmsGroupThread::query()
            ->accessibleTo($actor)
            ->whereJsonContains('participants', $e164)
            ->whereJsonLength('participants', 1)
            ->latest('last_activity_at')
            ->first();

        // Same shape as the Send Schedule modal's invite: greeting line, then
        // the ask — one voice wherever the link goes out.
        $firstName = $thread && $client
            ? ConsultScheduleLinkTexter::greetingNames($thread, $client, (string) ($lead->lead_data['name'] ?? ''))
            : strtok(trim((string) ($lead->lead_data['name'] ?? '')), ' ');

        $link = $this->shortener->shorten($lead->availabilityUrl());
        $text = 'Hi'.($firstName ? " {$firstName}" : '').",\n\nPick a consultation time with {$contractor} here: {$link}";

        if ($thread && $thread->hasPendingOptIn()) {
            return $this->result(false, 'warning', 'Awaiting START reply', 'This number has not replied START yet — the link can go out once they do.');
        }

        if ($thread) {
            $this->sms->sendToThread($thread, $text, [], $actor->id);

            return $this->result(true, 'success', 'Texted', 'Schedule link sent to '.phone_display($digits).'.');
        }

        $this->sms->sendNewGroup([$e164], $text, null, $client?->id, $actor->id, $actor->vendor?->id);

        return $this->result(true, 'success', 'Consent request sent', 'New number — they get the START prompt first. Text the link from Messages once they opt in.');
    }

    /** @return array{ok: bool, variant: string, heading: string, text: string} */
    protected function result(bool $ok, string $variant, string $heading, string $text): array
    {
        return compact('ok', 'variant', 'heading', 'text');
    }
}
