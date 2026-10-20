<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\LookupTeamMemberRequest;
use App\Http\Requests\Api\App\StoreTeamMemberRequest;
use App\Http\Requests\Api\App\UpdateNotificationSettingsRequest;
use App\Http\Requests\Api\App\UpdateUserRequest;
use App\Http\Resources\Api\App\TeamUserResource;
use App\Models\User;
use App\Services\Users\AppTeamDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Team tab, a member's detail, and the signed-in person's own
 * profile + notification settings — see
 * App\Livewire\Users\{UsersIndex,UserShow,UserDetails,UserCreate,UserNotificationSettings}
 * and App\Services\Users\AppTeamDirectory.
 */
class UserController extends Controller
{
    public function index(Request $request, AppTeamDirectory $directory): JsonResponse
    {
        $user = $request->user();
        $members = $directory->teamMembers($user->vendor);

        return response()->json([
            'data' => $members->map(fn (User $member) => (new TeamUserResource($member))->toArray($request))->values()->all(),
            // The web's "Add Member" button —
            // @can('create_team_member', [App\Models\User::class, $vendor->id])
            // on resources/views/livewire/users/index.blade.php.
            'can_add_team_member' => $request->user()->can('create_team_member', [User::class, $user->vendor->id]),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => (new TeamUserResource($request->user()))->withDetail()->toArray($request),
        ]);
    }

    public function show(Request $request, int $user): JsonResponse
    {
        $found = $this->visibleUser($request, $user);

        return response()->json([
            'data' => (new TeamUserResource($found))->withDetail()->toArray($request),
        ]);
    }

    /**
     * "Add Member"'s phone lookup, before role/pay-routing is chosen —
     * UserCreate::user_cell_find() for the vendor branch, see
     * AppTeamDirectory::lookupForTeamMember().
     */
    public function lookup(LookupTeamMemberRequest $request, AppTeamDirectory $directory): JsonResponse
    {
        return response()->json([
            'data' => $directory->lookupForTeamMember($request->user()->vendor, $request->string('cell_phone')->value()),
        ]);
    }

    /** "Add Member" on the team — the vendor branch of UserCreate::save(). */
    public function store(StoreTeamMemberRequest $request, AppTeamDirectory $directory): JsonResponse
    {
        $vendor = $request->user()->vendor;
        $this->authorize('create_team_member', [User::class, $vendor->id]);

        $member = $directory->addTeamMember($vendor, $request->validated());

        return response()->json([
            'data' => (new TeamUserResource($member))->withDetail()->toArray($request),
        ], 201);
    }

    /** "Edit User" — App\Livewire\Forms\UserForm::update(), for a team member or a client person alike. */
    public function update(UpdateUserRequest $request, int $user, AppTeamDirectory $directory): JsonResponse
    {
        $found = User::query()->findOrFail($user);
        $this->authorize('update', $found);

        $directory->updateUser($found, $request->user(), $request->validated());

        return response()->json([
            'data' => (new TeamUserResource($found->refresh()))->withDetail()->toArray($request),
        ]);
    }

    /**
     * "Remove User from Vendor" — App\Livewire\Users\UserCreate::removeMember().
     * Ends $user's employment on THEIR OWN primary vendor; the gate is on
     * that vendor (VendorPolicy::update), same as the web's menu item —
     * an Admin may only remove a teammate from their own company.
     */
    public function destroy(Request $request, int $user, AppTeamDirectory $directory): JsonResponse
    {
        $found = User::query()->findOrFail($user);
        abort_unless($found->vendor, 404);
        $this->authorize('update', $found->vendor);

        $directory->removeTeamMember($found);

        return response()->json(['data' => ['removed' => true]]);
    }

    /** App\Livewire\Users\UserNotificationSettings — always the signed-in person's own. */
    public function notificationSettings(Request $request, AppTeamDirectory $directory): JsonResponse
    {
        return response()->json([
            'data' => $directory->notificationSettings($request->user()),
        ]);
    }

    public function updateNotificationSettings(UpdateNotificationSettingsRequest $request, AppTeamDirectory $directory): JsonResponse
    {
        $directory->saveNotificationSettings($request->user(), $request->validated());

        return response()->json([
            'data' => $directory->notificationSettings($request->user()->refresh()),
        ]);
    }

    /** A user the person may see: 404 when no such account exists at all, 403 when UserPolicy::view refuses. */
    private function visibleUser(Request $request, int $id): User
    {
        $found = User::query()->find($id);
        abort_unless($found, 404);

        $this->authorize('view', $found);

        return $found;
    }
}
