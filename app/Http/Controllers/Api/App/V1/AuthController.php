<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Enums\OneTimeLoginCodeResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\AppLoginRequest;
use App\Http\Requests\Api\App\SendLoginCodeRequest;
use App\Http\Resources\Api\App\UserResource;
use App\Models\User;
use App\Services\Auth\OneTimeLoginCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in and session endpoints for the Hive Contractors app: email
 * one-time codes via App\Services\Auth\OneTimeLoginCodes (the same service
 * and cache keys the web /one-time-login page uses), then a Sanctum
 * personal access token named after the device, scoped to the "app"
 * ability.
 */
class AuthController extends Controller
{
    /**
     * Mail a fresh code when the address belongs to an allowed GS
     * Construction team member and isn't already within its resend
     * cooldown. Always 202 regardless of either, so the response never
     * reveals whether an account exists or is allowed.
     */
    public function sendCode(SendLoginCodeRequest $request, OneTimeLoginCodes $codes): JsonResponse
    {
        $email = $request->validated('email');
        $user = User::where('email', $email)->first();

        if ($user && $this->isAllowed($user) && ! $codes->withinCooldown($email)) {
            $codes->issue($user);
        }

        return response()->json(['sent' => true], 202);
    }

    /**
     * Verify the mailed code and issue a device-scoped Sanctum token.
     */
    public function login(AppLoginRequest $request, OneTimeLoginCodes $codes): JsonResponse
    {
        $data = $request->validated();
        $user = User::where('email', $data['email'])->first();

        // Checked before touching the code at all: a disallowed user must
        // not be able to log in even if they somehow still hold a valid
        // code, and must not get to consume (and burn) an attempt against
        // someone else's pending code.
        if (! $user || ! $this->isAllowed($user)) {
            throw ValidationException::withMessages([
                'email' => 'No account found with this email.',
            ]);
        }

        $result = $codes->verify($data['email'], $data['code']);

        if ($result !== OneTimeLoginCodeResult::Valid) {
            throw ValidationException::withMessages([
                'code' => $result->message(),
            ]);
        }

        $token = $user->createToken($data['device_name'], ['app']);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    /**
     * Revoke only the token this request authenticated with — other
     * devices stay signed in.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['logged_out' => true]);
    }

    private function isAllowed(User $user): bool
    {
        return (int) $user->primary_vendor_id === 1;
    }
}
