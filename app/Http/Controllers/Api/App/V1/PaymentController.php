<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StorePaymentRequest;
use App\Http\Resources\Api\App\PaymentResource;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Services\Payments\PaymentRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $page = $query->orderByDesc('date')->orderByDesc('id')->cursorPaginate(self::PER_PAGE);

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
     * balance remains), minus its "opened from this project" priority bump,
     * which has no phone-side equivalent yet.
     */
    public function eligibleProjects(Request $request): JsonResponse
    {
        $this->authorize('create', Payment::class);

        $clientId = $request->query('client_id');
        abort_unless(is_numeric($clientId), 422, 'client_id is required.');

        $client = Client::query()->whereKey((int) $clientId)->first();
        abort_unless($client, 404);

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
            ])->all(),
        ]);
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
