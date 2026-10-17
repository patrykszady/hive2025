<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\LoadsThreadForApp;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\App\ClientResource;
use App\Http\Resources\Api\App\ThreadResource;
use App\Models\Client;
use App\Models\SmsGroupThread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Clients tab: the same list, search and detail as the web
 * Clients index/show pages — see App\Models\Client (ClientScope is the
 * tenant boundary, reused here rather than re-derived) and
 * app/Livewire/Clients/ClientsIndex.php.
 */
class ClientController extends Controller
{
    use LoadsThreadForApp;

    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $query = Client::query()
            ->with('users')
            ->withCount('projects');

        $this->applySearch($query, $search);

        $page = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Client $client) => (new ClientResource($client))->toArray($request))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function show(Request $request, int $client): JsonResponse
    {
        $found = $this->visibleClient($client);
        $found->load(['users', 'projects.latestStatus']);

        return response()->json([
            'data' => (new ClientResource($found))->withDetail()->toArray($request),
        ]);
    }

    /**
     * The SMS thread for this client, if one exists and is visible to this
     * vendor (SmsGroupThread::scopeAccessibleTo() — the same visibility the
     * web Messages page uses), so the app's "Text" button can open it
     * directly instead of starting a new one.
     */
    public function thread(Request $request, int $client): JsonResponse
    {
        $user = $request->user();
        $found = $this->visibleClient($client);

        $thread = SmsGroupThread::query()
            ->accessibleTo($user)
            ->where('client_id', $found->id)
            ->orderByDesc('last_activity_at')
            ->first();

        if (! $thread) {
            return response()->json(['data' => null]);
        }

        $thread->load(self::THREAD_RELATIONS);
        $this->attachThreadUnreadCounts(collect([$thread]), $user);

        return response()->json(['data' => new ThreadResource($thread)]);
    }

    /** A client the person may see: 404 when it doesn't exist, 403 when it isn't theirs (via ClientScope). */
    private function visibleClient(int $id): Client
    {
        abort_unless(Client::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $client = Client::query()->whereKey($id)->first();
        abort_unless($client, 403, 'You do not have access to this client.');

        return $client;
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        try {
            $ids = Client::search($search)->take(200)->keys()->map(fn ($id) => (int) $id)->all();
            $query->whereIn('clients.id', $ids);

            return;
        } catch (\Throwable $exception) {
            report($exception);
        }

        // Meilisearch unavailable: a plain LIKE across the same fields the
        // web's search covers (business name, address, phone, people).
        $like = '%'.$search.'%';
        $digits = preg_replace('/\D/', '', $search);

        $query->where(function ($inner) use ($like, $digits) {
            $inner->where('business_name', 'like', $like)
                ->orWhere('address', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhereHas('users', function ($users) use ($like) {
                    $users->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like);
                });

            if (is_string($digits) && strlen($digits) >= 4) {
                $inner->orWhere('home_phone', 'like', "%{$digits}%");
            }
        });
    }
}
