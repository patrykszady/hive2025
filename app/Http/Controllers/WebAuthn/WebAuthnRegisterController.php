<?php

namespace App\Http\Controllers\WebAuthn;

use App\Traits\DetectsDeviceType;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

use function response;

class WebAuthnRegisterController
{
    use DetectsDeviceType;

    /**
     * Returns a challenge to be verified by the user device.
     */
    public function options(AttestationRequest $request): Responsable
    {
        return $request
            ->allowDuplicates()
            ->toCreate();
    }

    /**
     * Registers a device for further WebAuthn authentication.
     */
    public function register(AttestedRequest $request): Response
    {
        Log::channel('single')->info('WebAuthn register: Request received', [
            'user_id' => $request->user()?->id,
            'session_id' => session()->getId(),
            'auth_check' => auth()->check(),
        ]);
        $userAgent = (string) $request->header('User-Agent');
        $deviceType = $this->resolveDeviceTypeFromUserAgent($userAgent);
        $deviceName = $this->resolveDeviceName($deviceType, $userAgent);

        $request->save([
            'device_type' => $deviceType,
            'device_name' => $deviceName,
            'user_agent' => $userAgent,
            'alias' => $deviceName,
        ]);

        $user = $request->user();

        if ($user && $user->password !== null) {
            $user->password = null;
            $user->save();
        }

        return response()->noContent();
    }

    private function resolveDeviceName(string $deviceType, string $userAgent): string
    {
        if ($deviceType !== 'Unknown') {
            return $deviceType;
        }

        return $userAgent === '' ? 'Passkey' : 'Passkey (' . $deviceType . ')';
    }
}
