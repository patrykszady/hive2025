<?php

namespace App\Services\Voice;

use App\Models\User;
use App\Models\UserVoiceCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Team members' calling logins for the Hive Contractors app and the browser:
 * a Telnyx telephony credential each, on the app connection
 * (services.telnyx.app_connection_id). Found again by its tag before one is
 * created, so dev and production (one Telnyx account) share a person's
 * login instead of piling up duplicates.
 */
class AppVoiceCredentials
{
    private const API = 'https://api.telnyx.com/v2/telephony_credentials';

    /** Whether the app connection is configured at all. */
    public function enabled(): bool
    {
        return filled(config('services.telnyx.api_key')) && filled(config('services.telnyx.app_connection_id'));
    }

    /** The person's login, created at Telnyx the first time. */
    public function credentialFor(User $user): UserVoiceCredential
    {
        if ($existing = $user->voiceCredential()->first()) {
            return $existing;
        }

        if (! $this->enabled()) {
            throw new RuntimeException('The Hive Contractors app connection is not configured.');
        }

        $tag = $this->tagFor($user);
        $found = $this->http()->get(self::API, ['filter[tag]' => $tag, 'page[size]' => 1]);
        $credential = $found->successful() ? ($found->json('data.0') ?? null) : null;

        if (! $credential) {
            $created = $this->http()->post(self::API, [
                'connection_id' => (string) config('services.telnyx.app_connection_id'),
                'name' => 'Hive app – '.trim((string) $user->full_name),
                'tag' => $tag,
            ]);

            if (! $created->successful()) {
                Log::channel('telnyx')->error('App calling login could not be created', ['user_id' => $user->id, 'status' => $created->status(), 'error' => $created->json()]);

                throw new RuntimeException('Telnyx refused to create the calling login.');
            }

            $credential = $created->json('data');
            Log::channel('telnyx')->info('App calling login created', ['user_id' => $user->id, 'telnyx_credential_id' => $credential['id'] ?? null]);
        }

        return $user->voiceCredential()->create([
            'telnyx_credential_id' => (string) $credential['id'],
            'sip_username' => (string) $credential['sip_username'],
        ]);
    }

    /**
     * A short-lived sign-in token (JWT) for the person's app or browser:
     * the SDK logs in with it instead of a SIP password.
     */
    public function tokenFor(User $user): string
    {
        $credential = $this->credentialFor($user);
        $response = $this->http()->post(self::API.'/'.$credential->telnyx_credential_id.'/token');

        if (! $response->successful() || trim($response->body()) === '') {
            Log::channel('telnyx')->error('App calling token could not be minted', ['user_id' => $user->id, 'status' => $response->status()]);

            throw new RuntimeException('Telnyx refused to mint a calling token.');
        }

        $credential->forceFill(['last_token_at' => now()])->save();

        return trim($response->body());
    }

    /** Where Hive dials to ring this person's app, or null before they have one. */
    public function sipUriFor(User $user): ?string
    {
        return $user->voiceCredential()->first()?->sipUri();
    }

    protected function tagFor(User $user): string
    {
        return 'hive-app-user-'.$user->id;
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken((string) config('services.telnyx.api_key'))->acceptJson()->timeout(10);
    }
}
