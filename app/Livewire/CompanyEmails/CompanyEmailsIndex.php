<?php

namespace App\Livewire\CompanyEmails;

use App\Models\CompanyEmail;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class CompanyEmailsIndex extends Component
{
    use AuthorizesRequests;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public $view = null;

    /** How long a probe result is trusted before the page re-asks Nylas. */
    private const HEALTH_CACHE_MINUTES = 5;

    /**
     * Every account with its LIVE Nylas health attached.
     *
     * @return \Illuminate\Support\Collection<int, CompanyEmail>
     */
    #[Computed]
    public function accounts()
    {
        return CompanyEmail::all()->each(function (CompanyEmail $email) {
            $email->health = $this->grantHealth($email);
        });
    }

    /**
     * The dashboard's "valid" is the stored token's status, not a live test —
     * an expired app secret (2026-08-11: error 7000222) reads "valid" while
     * every real call 401s. So: check the grant AND perform an actual
     * one-message read, and when either fails surface the provider's own
     * error message as the reason.
     *
     * @return array{state: string, reason: ?string, provider: ?string, grant_email: ?string, checked_at: string}
     */
    protected function grantHealth(CompanyEmail $email): array
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

        return Cache::remember(
            "company_email_health:{$email->grant_id}",
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

                // The probe that actually tells the truth.
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

    /** Bust the probe cache and re-ask Nylas right now. */
    public function recheck(): void
    {
        foreach (CompanyEmail::whereNotNull('grant_id')->pluck('grant_id') as $grantId) {
            Cache::forget("company_email_health:{$grantId}");
        }

        unset($this->accounts);
    }

    #[Title('Email Accounts')]
    public function render()
    {
        $this->authorize('viewAny', CompanyEmail::class);

        return view('livewire.company-emails.index');
    }
}
