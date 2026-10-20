<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\LoginAsRequest;
use App\Http\Resources\Api\App\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The web's "Login As User" (App\Livewire\Users\AdminLoginAsUser) for the
 * app. The web swaps the session; the app gets a token for the chosen team
 * member that expires after a working day, and keeps its own to return to
 * (returning just revokes this token through the normal logout).
 *
 * Only people the app admits can be chosen (GS Construction's team — the same
 * primary_vendor_id 1 check as app.access), never Patryk himself.
 */
class LoginAsController extends Controller
{
    public const TOKEN_HOURS = 8;

    public function users(Request $request): JsonResponse
    {
        Gate::authorize('admin_login_as_user', User::class);

        $users = $this->candidates()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'cell_phone']);

        return response()->json([
            'data' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => trim($user->first_name.' '.$user->last_name),
                'email' => $user->email,
                'cell_phone' => $user->cell_phone,
            ])->values(),
        ]);
    }

    public function store(LoginAsRequest $request): JsonResponse
    {
        $target = $this->candidates()->find((int) $request->validated('user_id'));

        if (! $target) {
            throw ValidationException::withMessages(['user_id' => 'Choose someone on the team.']);
        }

        $admin = $request->user();
        $token = $target->createToken('app-login-as-by-'.$admin->id, ['app'], now()->addHours(self::TOKEN_HOURS));

        Log::info('App login-as', ['admin_id' => $admin->id, 'user_id' => $target->id]);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($target),
        ], 201);
    }

    /** People the app admits, other than Patryk. */
    private function candidates()
    {
        return User::withoutGlobalScopes()
            ->where('primary_vendor_id', 1)
            ->where('id', '!=', 1);
    }
}
