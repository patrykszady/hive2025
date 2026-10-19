<?php

namespace App\Services\CompanyEmails;

use App\Models\CompanyEmail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The app's Email Accounts screen — the same list + live Nylas health as
 * the web's /company_emails page (App\Livewire\CompanyEmails\
 * CompanyEmailsIndex). Connecting a mailbox (Nylas OAuth) and the plain
 * "Add Email" form stay web-only — see this track's final report.
 */
class AppCompanyEmailDirectory
{
    /** How long a probe result is trusted before the page re-asks Nylas — mirrors CompanyEmailsIndex::HEALTH_CACHE_MINUTES. */
    private const HEALTH_CACHE_MINUTES = 5;

    /**
     * Every account with its health attached, from cache only — mirrors
     * CompanyEmailsIndex::accounts()'s first paint (never calls Nylas).
     *
     * @return Collection<int, CompanyEmail>
     */
    public function list(): Collection
    {
        return CompanyEmail::all()->each(function (CompanyEmail $email) {
            $email->health = $this->health($email, allowProbe: false);
        });
    }

    /**
     * Probes Nylas for every linked account now — mirrors
     * CompanyEmailsIndex::probeHealth().
     *
     * @return Collection<int, CompanyEmail>
     */
    public function probe(): Collection
    {
        foreach (CompanyEmail::whereNotNull('grant_id')->get() as $email) {
            $this->health($email, allowProbe: true);
        }

        return $this->list();
    }

    /**
     * Busts every cached verdict and probes again — mirrors
     * CompanyEmailsIndex::recheck().
     *
     * @return Collection<int, CompanyEmail>
     */
    public function recheck(): Collection
    {
        foreach (CompanyEmail::whereNotNull('grant_id')->pluck('grant_id') as $grantId) {
            Cache::forget("company_email_health:{$grantId}");
        }

        return $this->probe();
    }

    /**
     * Mirrors CompanyEmailsIndex::grantHealth() exactly.
     *
     * @return array{state: string, reason: ?string, provider: ?string, grant_email: ?string, checked_at: string}
     */
    private function health(CompanyEmail $email, bool $allowProbe): array
    {
        if (! $email->grant_id) {
            return [
                'state' => 'unlinked',
                'reason' => 'No Nylas grant connected — reconnect this mailbox.',
                'provider' => null,
                'grant_email' => null,
                'checked_at' => now()->toDateTimeString(),
            ];
        }

        $cacheKey = "company_email_health:{$email->grant_id}";

        if (! $allowProbe) {
            return Cache::get($cacheKey) ?? [
                'state' => 'checking',
                'reason' => null,
                'provider' => null,
                'grant_email' => null,
                'checked_at' => now()->toDateTimeString(),
            ];
        }

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(self::HEALTH_CACHE_MINUTES),
            function () use ($email) {
                $base = rtrim(config('nylas.api_uri', 'https://api.us.nylas.com'), '/');
                $checkedAt = now()->toDateTimeString();

                $grant = Http::withToken(config('nylas.api_key'))
                    ->timeout(15)
                    ->get("$base/v3/grants/{$email->grant_id}");

                if (! $grant->successful()) {
                    return [
                        'state' => 'error',
                        'reason' => 'Grant lookup failed (HTTP '.$grant->status().'): '
                            .Str::limit((string) ($grant->json('error.message') ?? $grant->body()), 180),
                        'provider' => null,
                        'grant_email' => null,
                        'checked_at' => $checkedAt,
                    ];
                }

                $provider = $grant->json('data.provider');
                $grantEmail = $grant->json('data.email');
                $status = (string) $grant->json('data.grant_status');

                $probe = Http::withToken(config('nylas.api_key'))
                    ->timeout(20)
                    ->get("$base/v3/grants/{$email->grant_id}/messages", ['limit' => 1]);

                if ($probe->successful()) {
                    return [
                        'state' => 'connected',
                        'reason' => null,
                        'provider' => $provider,
                        'grant_email' => $grantEmail,
                        'checked_at' => $checkedAt,
                    ];
                }

                return [
                    'state' => 'error',
                    'reason' => ($status !== '' && $status !== 'valid' ? "Grant {$status} — " : '')
                        .Str::limit((string) ($probe->json('error.message') ?? ('HTTP '.$probe->status())), 220),
                    'provider' => $provider,
                    'grant_email' => $grantEmail,
                    'checked_at' => $checkedAt,
                ];
            },
        );
    }
}
