<?php

namespace App\Console\Commands;

use App\Jobs\SendLeadReplyJob;
use App\Models\CompanyEmail;
use App\Models\EmailTracking;
use App\Models\Lead;
use App\Models\Vendor;
use App\Services\UrlShortener;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Nudge the leads that went quiet.
 *
 * We replied (status "Replied") with the pick-times link, days passed, and
 * they neither booked nor sent new times. Today that lead sits in silence
 * until someone remembers to scroll the list; this sends ONE gentle follow-up
 * with the same booking link, marks the lead so it never nags twice, and
 * leaves the status alone — the ball stays in their court.
 */
class SendLeadFollowUps extends Command
{
    protected $signature = 'leads:follow-up
        {--days=3 : How long a lead may sit in Replied before the nudge}
        {--dry-run : Report who would be nudged without sending}';

    protected $description = 'Email one follow-up to Replied leads that never booked or rescheduled';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));
        $sent = 0;

        $candidates = Lead::withoutGlobalScopes()
            ->whereNull('lead_data->follow_up_sent_at')
            ->whereNotNull('lead_data->email')
            ->get()
            ->filter(fn (Lead $lead) => $lead->last_status?->title === 'Replied'
                && $lead->last_status->created_at <= $cutoff
                && ! $lead->hasRescheduled()
                && ! $lead->hasBookedConsult());

        foreach ($candidates as $lead) {
            // Continuity: the follow-up comes from whoever wrote to them last,
            // in the same voice. No prior tracked send → nothing to follow up.
            $lastSend = EmailTracking::withoutGlobalScopes()
                ->where('lead_id', $lead->id)
                ->where('event_type', 'sent')
                ->latest('event_at')
                ->first();

            if (! $lastSend) {
                continue;
            }

            $companyEmail = CompanyEmail::withoutGlobalScopes()
                ->where('vendor_id', $lead->belongs_to_vendor_id)
                ->whereNotNull('grant_id')
                ->when($lastSend->metadata['from_email'] ?? null, fn ($q, $from) => $q
                    ->orderByRaw('LOWER(email) = ? DESC', [strtolower($from)]))
                ->orderBy('id')
                ->first();

            if (! $companyEmail) {
                continue;
            }

            $email = (string) $lead->lead_data['email'];
            $vendor = Vendor::withoutGlobalScopes()->find($lead->belongs_to_vendor_id);
            $vendorName = $vendor?->name ?? config('app.name');
            $firstName = strtok(trim((string) ($lead->lead_data['name'] ?? '')), ' ');
            $link = app(UrlShortener::class)->shorten($lead->availabilityUrl());

            $shortVendorName = data_get($vendor?->options, 'short_name') ?: $vendorName;

            $body = '<p>Hi'.($firstName ? ' '.e($firstName) : '').',</p>'
                .'<p>Just checking in — we&rsquo;d still love to set up your consultation with '.e($vendorName).'. '
                .'You can <a href="'.e($link).'">pick a time that works for you here</a> and we&rsquo;ll confirm right away.</p>'
                .'<p>If plans have changed, no worries at all — just let us know.</p>'
                // Same signature block the Consult and estimate templates end
                // with, so every email from the company signs off identically.
                .'<p>Thank you,<br>'
                .e($shortVendorName).'<br>'
                .'Greg &amp; Patryk | (224) 735-4200<br>'
                .'<a href="https://www.gs.construction">www.gs.construction</a>'
                .' | <a href="https://www.google.com/search?sca_esv=25ed468a7ca66eef&amp;sxsrf=AE3TifOoLy3ZK-tf1GforAcFxLMvpg-10A:1763779038590&amp;q=www.gs.construction&amp;si=AMgyJEvWrqMtbdpM6zU9DoVHqM7BZVYVJqG6zLTeueLph2SDZZJ-_49tBx3xCMmoJtP1yZVsDm49UzGsIl1RL196h6P0M89Y6ApsniC7JsuiI1fRPLZzlFgDgIhf3y9lsIW4hpqzxSmAUpeg99wk9SB7c9SfyeHd7P5ecjiH-JG2d7eGBAil5eM%3D&amp;sa=X&amp;ved=2ahUKEwjWv7r43ISRAxX_GzQIHbSjKCEQ6RN6BAgQEAE&amp;biw=1496&amp;bih=877&amp;dpr=1.5">Google</a>'
                .' | <a href="https://www.houzz.com/pro/gs-construction">Best of Houzz</a>'
                .' | <a href="https://www.instagram.com/gs.construction.co/">Instagram</a></p>';

            $this->line(($this->option('dry-run') ? '[dry] ' : '')."lead {$lead->id} → {$email}");

            if (! $this->option('dry-run')) {
                SendLeadReplyJob::dispatch(
                    leadId: $lead->id,
                    companyEmailId: $companyEmail->id,
                    userId: (int) $lead->created_by_user_id,
                    recipients: [$email],
                    fromEmail: $companyEmail->email,
                    subject: 'Still interested? — '.$vendorName,
                    body: $body,
                    emailTemplateName: 'auto-follow-up',
                );

                // The marker is what makes this one-shot: written before the
                // queue runs so a crashed worker can duplicate a send only by
                // losing the job, never by re-selecting the lead.
                $data = $lead->lead_data instanceof \ArrayObject ? $lead->lead_data->toArray() : (array) $lead->lead_data;
                $data['follow_up_sent_at'] = now()->toDateTimeString();
                $lead->lead_data = $data;
                $lead->saveQuietly();
            }

            $sent++;
        }

        $this->info(($this->option('dry-run') ? 'Would send' : 'Sent')." {$sent} follow-up(s).");

        if ($sent > 0 && ! $this->option('dry-run')) {
            Log::info('Lead follow-ups sent', ['count' => $sent]);
        }

        return self::SUCCESS;
    }
}
