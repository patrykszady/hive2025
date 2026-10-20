<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\LoadsThreadForApp;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\AddClientPersonRequest;
use App\Http\Requests\Api\App\LookupClientPersonRequest;
use App\Http\Requests\Api\App\StoreClientRequest;
use App\Http\Requests\Api\App\UpdateClientRequest;
use App\Http\Resources\Api\App\ClientResource;
use App\Http\Resources\Api\App\ThreadResource;
use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Services\Clients\AppClientDirectory;
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
            // The web's "New Client" button — @can('create', Client::class)
            // on resources/views/livewire/clients/partials/table-actions.blade.php.
            'can_create_client' => $request->user()->can('create', Client::class),
        ]);
    }

    /**
     * Step one of "New Client": look up the contact person by phone —
     * App\Livewire\Users\UserCreate::user_cell_find() — and, if they
     * already belong to one of our clients, offer those instead of letting
     * an admin create a duplicate (App\Livewire\Clients\ClientCreate::addUser()'s
     * "Existing Clients" picker).
     */
    public function lookupPerson(LookupClientPersonRequest $request, AppClientDirectory $directory): JsonResponse
    {
        $this->authorize('create', Client::class);

        $person = $directory->findPersonByPhone($request->validated('cell_phone'));

        if (! $person) {
            return response()->json(['data' => ['person' => null, 'existing_clients' => []]]);
        }

        $existingClients = $directory->existingClientsFor($person);

        return response()->json([
            'data' => [
                'person' => [
                    'id' => $person->id,
                    'first_name' => $person->first_name,
                    'last_name' => $person->last_name,
                    'email' => $person->email,
                    'cell_phone' => $person->cell_phone,
                ],
                'existing_clients' => $existingClients->map(fn (Client $client) => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'address' => $client->address,
                ])->values()->all(),
            ],
        ]);
    }

    /**
     * "New Client" — App\Livewire\Clients\ClientCreate::save() +
     * App\Livewire\Forms\ClientForm::store(). `existing_client_id` mirrors
     * picking one of the "Existing Clients" radio options: nothing is
     * created, we just hand back that client (the web redirects to it).
     */
    public function store(StoreClientRequest $request, AppClientDirectory $directory): JsonResponse
    {
        $existingClientId = $request->validated('existing_client_id');

        if ($existingClientId) {
            $client = $this->visibleClient((int) $existingClientId);

            return response()->json([
                'data' => (new ClientResource($client))->withDetail()->toArray($request),
            ]);
        }

        $this->authorize('create', Client::class);

        $client = $directory->createClient($request->user(), $request->validated());
        $client->load(['users', 'projects.latestStatus']);

        return response()->json([
            'data' => (new ClientResource($client))->withDetail()->toArray($request),
        ], 201);
    }

    /** "Edit Client" — App\Livewire\Forms\ClientForm::update(). */
    public function update(UpdateClientRequest $request, int $client, AppClientDirectory $directory): JsonResponse
    {
        $found = $this->visibleClient($client);
        $this->authorize('update', $found);

        $directory->updateClient($found, $request->user(), $request->validated());
        $found->refresh()->load(['users', 'projects.latestStatus']);

        return response()->json([
            'data' => (new ClientResource($found))->withDetail()->toArray($request),
        ]);
    }

    /**
     * "Delete Client" — App\Livewire\Clients\ClientCreate::deleteClient(),
     * same gate (App\Policies\ClientPolicy::delete: an Admin, and only a
     * client with no projects).
     */
    public function destroy(Request $request, int $client, AppClientDirectory $directory): JsonResponse
    {
        $found = $this->visibleClient($client);
        $this->authorize('delete', $found);

        $directory->deleteClient($found);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * "Add Member" on a client — the client branch of
     * App\Livewire\Users\UserCreate::save() for an existing client id.
     */
    public function addPerson(AddClientPersonRequest $request, int $client, AppClientDirectory $directory): JsonResponse
    {
        $found = $this->visibleClient($client);
        $this->authorize('create_client_member', [User::class, $found]);

        $person = $directory->addPerson($found, $request->validated());

        return response()->json([
            'data' => [
                'id' => $person->id,
                'first_name' => $person->first_name,
                'last_name' => $person->last_name,
                'cell_phone' => $person->cell_phone,
                'email' => $person->email,
            ],
        ], 201);
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
        $thread->loadCount($this->scheduledMessagesCountRelation());
        $this->attachThreadUnreadCounts(collect([$thread]), $user);
        $this->attachThreadSpamFlags(collect([$thread]));

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
