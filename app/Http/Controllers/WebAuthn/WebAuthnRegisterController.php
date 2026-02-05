<?php

namespace App\Http\Controllers\WebAuthn;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

use function response;

class WebAuthnRegisterController
{
    /**
     * Returns a challenge to be verified by the user device.
     */
    public function options(AttestationRequest $request): Responsable
    {
        Log::channel('single')->info('WebAuthn options: Request received', [
            'user_id' => $request->user()?->id,
            'session_id' => session()->getId(),
            'auth_check' => auth()->check(),
        ]);

        return $request
            ->fastRegistration()
            ->allowDuplicates()
//            ->userless()
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
        $deviceType = $this->resolveDeviceType($userAgent);
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

    private function resolveDeviceType(string $userAgent): string
    {
        $ua = strtolower($userAgent);

        if ($ua === '') {
            return 'Unknown';
        }

        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios')) {
            return 'iOS';
        }

        if (str_contains($ua, 'android')) {
            return 'Android';
        }

        if (str_contains($ua, 'windows')) {
            return 'Windows';
        }

        if (str_contains($ua, 'macintosh') || str_contains($ua, 'mac os x')) {
            return 'macOS';
        }

        if (str_contains($ua, 'linux')) {
            return 'Linux';
        }

        return 'Unknown';
    }

    private function resolveDeviceName(string $deviceType, string $userAgent): string
    {
        if ($deviceType !== 'Unknown') {
            return $deviceType;
        }

        return $userAgent === '' ? 'Passkey' : 'Passkey (' . $deviceType . ')';
    }
}
