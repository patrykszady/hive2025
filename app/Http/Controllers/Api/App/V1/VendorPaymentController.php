<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreVendorPaymentRequest;
use App\Http\Resources\Api\App\CheckResource;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Vendors\VendorPaymentOptions;
use App\Services\Vendors\VendorPaymentRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The app's "Pay Vendor" screen — mirrors
 * App\Livewire\Vendors\VendorPaymentCreate (/vendors/{vendor}/payment)
 * exactly: the same eligible projects and unsettled reimbursements
 * (App\Services\Vendors\VendorPaymentOptions), and on save the exact same
 * write, including the delayed payment-notification email
 * (App\Services\Vendors\VendorPaymentRecorder). Both the GET and the POST
 * are gated by CheckPolicy::create — a single consistent Admin/non-1099
 * gate, exactly like the web (VendorPaymentCreate::mount()/save() both
 * check 'create' on Check::class, not a looser view-only ability).
 */
class VendorPaymentController extends Controller
{
    public function show(Request $request, int $vendor, VendorPaymentOptions $options): JsonResponse
    {
        $this->authorize('create', Check::class);
        $target = $this->visibleVendor($vendor);

        return response()->json([
            'data' => [
                'vendor' => ['id' => $target->id, 'name' => $target->name],
                'projects' => $options->projectsFor($target),
                'reimbursement_expenses' => $options->reimbursementExpensesFor($target)
                    ->map(fn (Expense $e) => [
                        'id' => $e->id,
                        'date' => $e->date?->format('Y-m-d'),
                        'amount' => (float) $e->amount,
                        'note' => $e->note,
                        'selected' => false,
                    ])->values()->all(),
                'employees' => $options->employeesFor($request->user())
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => trim($u->first_name.' '.$u->last_name)])
                    ->values()->all(),
                'bank_accounts' => $options->bankAccounts()
                    ->map(fn (BankAccount $a) => [
                        'id' => $a->id,
                        'label' => $a->getNameAndType(),
                        'next_check_number' => $options->nextCheckNumber($a),
                    ])->values()->all(),
            ],
        ]);
    }

    public function store(StoreVendorPaymentRequest $request, int $vendor, VendorPaymentOptions $options, VendorPaymentRecorder $recorder): JsonResponse
    {
        $this->authorize('create', Check::class);
        $target = $this->visibleVendor($vendor);

        // $projects rides in on caller-controlled input. The web structurally
        // can never submit a project id outside VendorPaymentOptions'
        // offered set (its own $projects array is built from that exact
        // query), so the app intersects against the same offer before the
        // total is even computed — otherwise a forged/foreign id could
        // still slip a harmless-looking $0 check through (VendorPaymentRecorder
        // would skip the inaccessible project, but only AFTER the check
        // itself was already written).
        $offeredProjectIds = collect($options->projectsFor($target))->pluck('id');
        $projects = collect($request->validated('projects'))
            ->filter(fn (array $p) => $offeredProjectIds->contains((int) ($p['id'] ?? null)))
            ->map(fn (array $p) => ['id' => $p['id'], 'amount' => $p['amount'] ?? null])
            ->values()->all();

        // Reimbursement ids are intersected against what this vendor
        // genuinely has unsettled, never trusted outright.
        $offeredReimbursementIds = $options->reimbursementExpensesFor($target)->pluck('id');
        $reimbursementIds = $offeredReimbursementIds
            ->intersect(collect((array) $request->validated('reimbursement_expense_ids', []))->map(fn ($id) => (int) $id))
            ->values()->all();

        $total = collect($projects)->sum(fn (array $p) => is_numeric($p['amount'] ?? null) && $p['amount'] > 0 ? (float) $p['amount'] : 0.0)
            - Expense::whereIn('id', $reimbursementIds)->sum('amount');

        if ($total <= 0) {
            return response()->json([
                'message' => 'Check total needs to be greater than $0 and include at least 1 project.',
                'errors' => ['check_total' => ['Check total needs to be greater than $0 and include at least 1 project.']],
            ], 422);
        }

        try {
            $check = $recorder->record(
                $request->user(),
                $target,
                (string) $request->validated('date'),
                $request->validated('check_type'),
                $request->filled('check_number') ? (int) $request->validated('check_number') : null,
                $request->filled('bank_account_id') ? (int) $request->validated('bank_account_id') : null,
                $request->filled('paid_by') ? (int) $request->validated('paid_by') : null,
                $request->validated('invoice'),
                $projects,
                $reimbursementIds,
            );
        } catch (ValidationException $e) {
            $firstMessage = collect($e->errors())->flatten()->first() ?? 'Could not save this payment.';

            return response()->json(['message' => $firstMessage, 'errors' => $e->errors()], 422);
        }

        if (! $check) {
            return response()->json(['data' => ['check' => null]]);
        }

        $check->load(['bank_account.bank', 'vendor', 'user']);

        return response()->json(['data' => ['check' => (new CheckResource($check))->withDetail()->toArray($request)]], 201);
    }

    /** A vendor the person may see: 404 when it doesn't exist anywhere, 403 when VendorPolicy::view refuses it. */
    private function visibleVendor(int $id): Vendor
    {
        abort_unless(Vendor::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $vendor = Vendor::query()->whereKey($id)->first();
        abort_unless($vendor && auth()->user()->can('view', $vendor), 403, 'You do not have access to this vendor.');

        return $vendor;
    }
}
