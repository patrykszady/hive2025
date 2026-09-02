<?php

namespace App\Http\Controllers\WebAuthn;

use App\Models\User;
use App\Traits\DetectsDeviceType;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;
use Laragear\WebAuthn\Models\WebAuthnCredential;

use function response;

/**
 * Passkey sign-in. Every step writes to the `passkeys` channel: registration
 * already did, but a failed LOGIN left no trace anywhere — not client-side,
 * not here — which is why "can't log in with my passkey" was undiagnosable.
 * Read the sequence: options requested → (browser ceremony, logged by the
 * page) → success / assertion rejected, with what the server knew about the
 * credential the browser presented.
 */
class WebAuthnLoginController
{
    use DetectsDeviceType;

    /**
     * Returns the challenge to assertion.
     */
    public function options(AssertionRequest $request): Responsable
    {
        $validated = $request->validate(['email' => 'sometimes|email|string']);

        $user = isset($validated['email']) ? User::where('email', $validated['email'])->first() : null;
        $credentials = $user
            ? WebAuthnCredential::query()
                ->where('authenticatable_id', $user->id)
                ->whereNull('disabled_at')
                ->get(['id', 'device_type', 'rp_id'])
            : collect();

        Log::channel('passkeys')->info('WebAuthn login: options requested', [
            'email' => $validated['email'] ?? null,
            'user_id' => $user?->id,
            'current_device' => $this->currentDeviceType(),
            'credentials_offered' => $credentials->count(),
            'credential_devices' => $credentials->pluck('device_type')->countBy()->all(),
            'session_id' => session()->getId(),
        ]);

        return $request->toVerify($validated);
    }

    /**
     * Log the user in.
     */
    public function login(AssertedRequest $request): Response
    {
        $credentialId = (string) $request->input('id', '');
        $credential = $credentialId !== '' ? WebAuthnCredential::find($credentialId) : null;

        $context = [
            'credential_id' => $credentialId !== '' ? substr($credentialId, 0, 12).'…' : null,
            // A presented credential the server has never seen was minted
            // elsewhere (another environment, a different relying party).
            'credential_known' => $credential !== null,
            'credential_user_id' => $credential?->authenticatable_id,
            'credential_device' => $credential?->device_type,
            'credential_disabled' => $credential?->disabled_at !== null,
            'current_device' => $this->currentDeviceType(),
            'remember' => $request->hasRemember(),
            'session_id' => session()->getId(),
        ];

        $user = $request->login();

        if ($user) {
            Log::channel('passkeys')->info('WebAuthn login: success', $context + ['user_id' => $user->getAuthIdentifier()]);

            return response()->noContent(204);
        }

        Log::channel('passkeys')->warning('WebAuthn login: assertion rejected', $context);

        return response()->noContent(422);
    }
}
