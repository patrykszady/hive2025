<?php

namespace App\Services\Voice;

use App\Models\PushToken;
use App\Models\User;
use App\Models\UserVoiceCredential;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * How long after a phone's copy of the app last talked to Hive it still
     * counts as there (hasRingableApp()). A phone that is lost, broken or
     * switched off for good never signs out and nothing prunes its
     * registration; this is what gives its owner their cell back. A week
     * covers a long weekend with the app untouched; someone back from longer
     * rings on their cell as well until the app next talks to Hive.
     */
    public const APP_SEEN_WITHIN_DAYS = 7;

    /**
     * The phones a call can wake. An Android phone cannot be yet: its
     * incoming-call push needs a Firebase credential on the app connection
     * that has not been set up, so it only rings while the app is open.
     *
     * @var list<string>
     */
    public const RINGABLE_PLATFORMS = [PushToken::PLATFORM_IOS];

    /** Whether the app connection is configured at all. */
    public function enabled(): bool
    {
        return filled(config('services.telnyx.api_key')) && filled(config('services.telnyx.app_connection_id'));
    }

    /**
     * Whether "direct dial" (the app's own Telnyx SDK places the call,
     * parked at Telnyx rather than ringing the person's phone first) is
     * configured and turned on. See config('services.telnyx.direct_dial_enabled').
     */
    public function directDialEnabled(): bool
    {
        return $this->enabled() && (bool) config('services.telnyx.direct_dial_enabled');
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

    /**
     * Whether an incoming call can ring this person's Hive Contractors app
     * right now, as far as Hive can know — the test behind "app only"
     * (Patryk, 2026-11-12: someone who has the app is rung in the app and
     * never on their cell; see TelnyxWebhookController::dialPersonAppLeg()).
     *
     * A calling login alone does not say so: the browser phone, the
     * /phone-system/app-calling page and internal calls create one too, and
     * it outlives a sign-out or an uninstall. True only when all of these
     * hold, and anything less rings the cell:
     *
     *  - app calling is configured and the person has a calling login;
     *  - an iPhone is signed in to the app as them — a push_tokens row,
     *    which signing out deletes (AuthController::logout()) and Expo's
     *    "device not registered" prunes after an uninstall (ExpoPushClient);
     *  - that phone is not switched off on the users page (`enabled`);
     *  - the phone signed in by itself: the row hangs on a sign-in token
     *    that never expires. An admin's "Login As" registers the admin's
     *    own phone under the team member with an 8-hour token
     *    (LoginAsController), and a row whose token is gone is a leftover;
     *  - that copy of the app talked to Hive within APP_SEEN_WITHIN_DAYS:
     *    it re-registered its push token (`last_seen_at`) or made any
     *    request with its sign-in token (`last_used_at`).
     */
    public function hasRingableApp(User $user): bool
    {
        if (! $this->enabled() || ! $user->voiceCredential()->exists()) {
            return false;
        }

        $seenSince = now()->subDays(self::APP_SEEN_WITHIN_DAYS);

        return $user->pushTokens()
            ->enabled()
            ->whereIn('platform', self::RINGABLE_PLATFORMS)
            ->whereHas('personalAccessToken', fn (Builder $signIn) => $signIn->whereNull('expires_at'))
            ->where(fn (Builder $seen) => $seen
                ->where('last_seen_at', '>=', $seenSince)
                ->orWhereHas('personalAccessToken', fn (Builder $signIn) => $signIn->where('last_used_at', '>=', $seenSince)))
            ->exists();
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
