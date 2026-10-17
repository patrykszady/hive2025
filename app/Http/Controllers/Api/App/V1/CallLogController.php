<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\UnblockNumberRequest;
use App\Http\Resources\Api\App\CallLogResource;
use App\Models\CallLog;
use App\Models\User;
use App\Services\Calls\CallHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Calls tab: the same list, filters, search, grouping, detail and
 * blocking as the web calls dashboard (/messages?activeTab=calls), through
 * CallHistory and CallTranscriptPresenter.
 */
class CallLogController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request, CallHistory $history): JsonResponse
    {
        $filter = in_array($request->query('filter'), CallHistory::FILTERS, true) ? (string) $request->query('filter') : 'all';

        $page = $history->query($request->user(), $filter, (string) $request->query('search', ''))
            ->orderByDesc('id')
            ->with('transcript')
            ->cursorPaginate(self::PER_PAGE);

        $grouped = $history->group($page->getCollection());
        $answeredBy = $this->answeringMembers($page->getCollection()->pluck('forwarded_to')->filter()->unique()->all());

        return response()->json([
            'data' => $grouped->map(fn (array $row) => (new CallLogResource($row['call']))->grouped($row['count'], $history, $answeredBy)->toArray($request))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function show(Request $request, int $call, CallHistory $history): JsonResponse
    {
        $found = $this->visibleCall($request->user(), $call);
        $found->load('transcript');
        $history->resolveNumbers([$found]);

        return response()->json(['data' => (new CallLogResource($found))->withDetail($history)->toArray($request)]);
    }

    public function block(Request $request, int $call, CallHistory $history): JsonResponse
    {
        $phone = $history->block($request->user(), $this->visibleCall($request->user(), $call));

        if (! $phone) {
            return response()->json(['message' => 'No phone number found for this call.'], 422);
        }

        return response()->json(['blocked' => true, 'phone' => $phone]);
    }

    public function unblock(UnblockNumberRequest $request, CallHistory $history): JsonResponse
    {
        $phone = (string) $request->validated('phone');

        if (! $history->unblock($request->user(), $phone)) {
            return response()->json(['message' => 'That number is not blocked.'], 422);
        }

        return response()->json(['blocked' => false, 'phone' => $phone]);
    }

    /** A call the person may see: 404 when it doesn't exist, 403 when it isn't theirs to see. */
    protected function visibleCall(User $user, int $callId): CallLog
    {
        abort_unless(CallLog::query()->whereKey($callId)->exists(), 404);

        $call = CallLog::query()->visibleToMessagesUser($user)->find($callId);
        abort_unless($call, 403, 'You do not have access to this call.');

        return $call;
    }

    /**
     * The team members whose cells answered these calls, by number, in one query.
     *
     * @param  array<int, string>  $phones
     * @return array<string, string>
     */
    protected function answeringMembers(array $phones): array
    {
        if ($phones === []) {
            return [];
        }

        $digits = collect($phones)->mapWithKeys(fn ($phone) => [(string) $phone => substr((string) preg_replace('/\D/', '', (string) $phone), -10)]);

        $users = User::query()
            ->whereIn('cell_phone', $digits->values()->unique()->all())
            ->get(['id', 'first_name', 'last_name', 'cell_phone'])
            ->keyBy(fn (User $user) => substr((string) preg_replace('/\D/', '', (string) $user->cell_phone), -10));

        return $digits->map(fn (string $last10) => $users->get($last10)?->full_name)->filter()->all();
    }
}
