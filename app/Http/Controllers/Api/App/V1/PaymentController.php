<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StorePaymentRequest;
use App\Http\Requests\Api\App\UpdatePaymentRequest;
use App\Http\Resources\Api\App\PaymentResource;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Services\Payments\PaymentRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The app's Payments tab — Admin-only, exactly like the web: PaymentPolicy's
 * viewAny/view/create all require vendor_role === 'Admin' (the one
 * is_browsing_as_client branch never applies here, since the Hive
 * Contractors app is GS Construction's own team). See
 * App\Livewire\Payments\PaymentsIndex, PaymentShow, PaymentCreate, and
 * App\Services\Payments\PaymentRecorder for the create side effects shared
 * with PaymentForm::store().
 */
class PaymentController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $query = $this->filteredQuery($request);
        $totalAmount = (clone $query)->sum('amount');

        $query->with(['project.client.users:id,first_name,last_name,nickname']);

        // Only the Date column is actually wired to wire:click="sort('date')"
        // on the web (resources/views/livewire/payments/index.blade.php) —
        // PaymentsIndex::SORTABLE_COLUMNS also whitelists amount/reference/
        // created_at, but nothing in the template ever calls sort() with
        // them — so Date is the only direction the app offers too.
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $page = $query->orderBy('date', $direction)->orderBy('id', $direction)->cursorPaginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Payment $payment) => (new PaymentResource($payment))->toArray($request))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
            // A sum across the filtered set, not just the page on screen —
            // the web's index shows no total at all, but a running total is
            // the one thing a phone-sized list benefits from that a wide
            // table doesn't need.
            'total_amount' => round((float) $totalAmount, 2),
        ]);
    }

    public function show(Request $request, int $payment): JsonResponse
    {
        $found = $this->visiblePayment($payment);
        $this->authorize('view', $found);

        $found->load(['project.client.users', 'transaction.bank_account.bank']);

        return response()->json(['data' => (new PaymentResource($found))->withDetail()->toArray($request)]);
    }

    /**
     * Candidate projects for a new payment against this client — the same
     * filter and order PaymentCreate::updatedClientId() builds for the web's
     * project picker (Active always; Completed/Service Call only once a
     * balance remains), including PaymentCreate::addProject()'s "opened
     * from this project" priority bump (`?opened_from_project_id=`): that
     * project is sorted first and flagged `is_current_project`, same as the
     * web's own sortBy() tiebreaker and its "This project" badge.
     */
    public function eligibleProjects(Request $request): JsonResponse
    {
        $this->authorize('create', Payment::class);

        $clientId = $request->query('client_id');
        abort_unless(is_numeric($clientId), 422, 'client_id is required.');

        $client = Client::query()->whereKey((int) $clientId)->first();
        abort_unless($client, 404);

        $openedFromProjectId = $request->query('opened_from_project_id');
        $openedFromProjectId = is_numeric($openedFromProjectId) ? (int) $openedFromProjectId : null;

        $projects = $client->projects()
            ->status([6, 7, 8]) // Active, Complete, Service Call
            ->with('latestStatus')
            ->get()
            ->filter(function (Project $project) {
                if ($project->latestStatus?->status_code === 6) {
                    return true;
                }
                if (in_array($project->latestStatus?->status_code, [7, 8], true)) {
                    return $project->finances['balance'] > 0;
                }

                return true;
            })
            ->sortBy([
                fn ($a, $b) => (int) ($b->id === $openedFromProjectId) <=> (int) ($a->id === $openedFromProjectId),
                ['latestStatus.status_code', 'asc'],
                ['latestStatus.start_date', 'desc'],
            ])
            ->values();

        return response()->json([
            'data' => $projects->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->project_name,
                'address' => $project->short_address,
                'balance' => round((float) ($project->finances['balance'] ?? 0), 2),
                'status' => $project->latestStatus ? [
                    'code' => $project->latestStatus->status_code,
                    'title' => $project->latestStatus->title,
                ] : null,
                'is_current_project' => $openedFromProjectId !== null && $project->id === $openedFromProjectId,
            ])->all(),
        ]);
    }

    /**
     * The standalone Payments list's Project/Client filter options — the
     * exact same cached lists PaymentsIndex::mount() builds for the web's
     * filter dropdowns (same cache keys, so the app never duplicates that
     * work): every project/client with at least one payment on this vendor.
     */
    public function filterOptions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $vendorId = $request->user()->vendor->id;

        $projects = Cache::remember("filters:v{$vendorId}:payment-projects", 600, function () use ($vendorId) {
            return Project::where('belongs_to_vendor_id', $vendorId)
                ->whereHas('payments')
                ->orderByDesc('created_at')
                ->get(['id', 'project_name', 'address']);
        });

        $clients = Cache::remember("filters:v{$vendorId}:payment-clients:v2", 600, function () use ($vendorId) {
            return Client::whereHas('projects.payments', function ($query) use ($vendorId) {
                $query->where('belongs_to_vendor_id', $vendorId);
            })
                ->with('users:id,first_name,last_name,nickname')
                ->orderBy('business_name')
                ->orderBy('id')
                ->get(['id', 'business_name'])
                ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name]);
        });

        return response()->json(['data' => [
            'projects' => $projects->map(fn (Project $project) => ['id' => $project->id, 'name' => $project->project_name])->values()->all(),
            'clients' => $clients->values()->all(),
        ]]);
    }

    public function store(StorePaymentRequest $request, PaymentRecorder $recorder): JsonResponse
    {
        $this->authorize('create', Payment::class);

        /** @var list<array{id: int, amount: mixed}> $projects */
        $projects = $request->validated('projects');

        // Mirrors PaymentCreate::hasPaymentTotal() — enforced before
        // storing, not as a validation rule, since 0.00 per-row is already
        // rejected above and this is a cross-row sum check.
        $total = round(collect($projects)->sum(fn (array $project) => $recorder->parseAmount($project['amount'] ?? null)), 2);

        if ($total === 0.0) {
            return response()->json([
                'message' => 'Payment total needs to include at least 1 project and not equal $0.00',
            ], 422);
        }

        $payment = $recorder->record(
            $request->user(),
            $projects,
            (string) $request->validated('date'),
            (string) $request->validated('reference'),
            $request->validated('note'),
        );

        abort_if($payment === null, 422, 'The payment could not be saved to these projects. Check the amounts and try again.');

        $payment->load(['project.client.users']);

        return response()->json([
            'data' => (new PaymentResource($payment))->withDetail()->toArray($request),
        ], 201);
    }

    /**
     * Mirrors App\Livewire\Payments\PaymentCreate::update(): the same
     * "projects sum to $0.00" guard before delegating to PaymentRecorder,
     * whose update() does the grouping/collapsing logic PaymentForm::update()
     * shares with this endpoint.
     */
    public function update(UpdatePaymentRequest $request, PaymentRecorder $recorder, int $payment): JsonResponse
    {
        $found = $this->visiblePayment($payment);
        $this->authorize('view', $found);

        /** @var list<array{id: int, amount: mixed}> $projects */
        $projects = $request->validated('projects');

        $total = round(collect($projects)->sum(fn (array $project) => $recorder->parseAmount($project['amount'] ?? null)), 2);

        if ($total === 0.0) {
            return response()->json([
                'message' => 'Payment total needs to include at least 1 project and not equal $0.00',
            ], 422);
        }

        $updated = $recorder->update(
            $found,
            $projects,
            (string) $request->validated('date'),
            (string) $request->validated('reference'),
            $request->validated('note'),
            $request->user(),
        );

        $updated->load(['project.client.users']);

        return response()->json([
            'data' => (new PaymentResource($updated))->withDetail()->toArray($request),
        ]);
    }

    /**
     * Deletes exactly this one payment row — mirrors
     * PaymentCreate::remove(), which deletes only $this->payment (the row
     * the edit modal was opened for), never its whole group of siblings.
     */
    public function destroy(Request $request, int $payment): JsonResponse
    {
        $found = $this->visiblePayment($payment);
        $this->authorize('view', $found);
        $found->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function filteredQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = Payment::query();

        $amount = trim((string) $request->query('amount', ''));
        $projectId = $request->query('project_id');
        $clientId = $request->query('client_id');
        $status = $request->query('status');

        if ($amount !== '') {
            $query->where('amount', 'like', $amount.'%');
        }

        if (is_numeric($projectId)) {
            $query->where('project_id', (int) $projectId);
        }

        if (is_numeric($clientId)) {
            $query->whereHas('project', fn ($projectQuery) => $projectQuery->where('client_id', (int) $clientId));
        }

        if ($status === 'complete') {
            $query->whereNotNull('transaction_id');
        } elseif ($status === 'missing') {
            $query->whereNull('transaction_id');
        }

        return $query;
    }

    /** A payment the person may see: 404 when it doesn't exist, 403 when it isn't theirs (via PaymentScope). */
    private function visiblePayment(int $id): Payment
    {
        abort_unless(Payment::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $payment = Payment::query()->whereKey($id)->first();
        abort_unless($payment, 403, 'You do not have access to this payment.');

        return $payment;
    }
}
