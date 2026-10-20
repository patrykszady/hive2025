<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreTimesheetPaymentRequest;
use App\Http\Resources\Api\App\CheckResource;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Timesheets\TimesheetPaymentOptions;
use App\Services\Timesheets\TimesheetPaymentRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The app's "Pay" screen — mirrors
 * App\Livewire\Timesheets\TimesheetPaymentCreate (/timesheets/payment/{user})
 * exactly: the same unsettled rows in the same default selection
 * (App\Services\Timesheets\TimesheetPaymentOptions), and on save the exact
 * same write (App\Services\Timesheets\TimesheetPaymentRecorder).
 *
 * Authorization mirrors the web precisely, and the two actions are gated
 * DIFFERENTLY on purpose: viewing the page is TimesheetPolicy::viewPayment
 * (the member may always see their own page; an Admin may see anyone
 * employed by their company), but actually submitting a payment is
 * TimesheetPolicy::viewAnyPayment (Admin, non-1099, only) — exactly like
 * TimesheetPaymentCreate::confirmPayment()/save(), which re-check
 * viewAnyPayment rather than reusing the looser viewPayment gate mount()
 * used. A Member (e.g. Cezary) can open their own balance here but the
 * store() route always 403s them.
 */
class TimesheetPaymentController extends Controller
{
    public function show(Request $request, int $user, TimesheetPaymentOptions $options): JsonResponse
    {
        $target = $this->visibleUser($user);
        $this->authorize('viewPayment', [Timesheet::class, $target]);

        $found = $options->forUser($request->user(), $target);

        $defaultTotal = $options->totalForSelection($target, [
            'weekly_timesheet_ids' => $found['weekly_timesheets']->pluck('id')->all(),
            'employee_weekly_timesheet_ids' => $found['employee_weekly_timesheets']->pluck('id')->all(),
            'paid_expense_ids' => [],
            'reimbursement_expense_ids' => [],
        ]);

        return response()->json([
            'data' => [
                'payee_name' => $found['payee_name'],
                'user' => ['id' => $target->id, 'name' => $target->full_name],
                'can_pay' => Gate::forUser($request->user())->allows('viewAnyPayment', Timesheet::class),
                'weekly_timesheets' => $found['weekly_timesheets']->map(fn (Timesheet $t) => $this->timesheetRow($t, true))->values()->all(),
                'employee_weekly_timesheets' => $found['employee_weekly_timesheets']->map(fn (Timesheet $t) => $this->timesheetRow($t, true, withPayer: true))->values()->all(),
                'paid_expenses' => $found['paid_expenses']->map(fn (Expense $e) => $this->expenseRow($e, false))->values()->all(),
                'reimbursement_expenses' => $found['reimbursement_expenses']->map(fn (Expense $e) => $this->expenseRow($e, false))->values()->all(),
                'default_total' => round($defaultTotal, 2),
                'employees' => $found['employees']->map(fn (User $u) => ['id' => $u->id, 'name' => trim($u->first_name.' '.$u->last_name)])->values()->all(),
                'bank_accounts' => $this->bankAccountsArray($found['bank_accounts'], $options),
            ],
        ]);
    }

    public function store(StoreTimesheetPaymentRequest $request, int $user, TimesheetPaymentOptions $options, TimesheetPaymentRecorder $recorder): JsonResponse
    {
        $target = $this->visibleUser($user);
        $this->authorize('viewAnyPayment', Timesheet::class);

        // Every selection id is intersected against what TimesheetPaymentOptions
        // genuinely offers $target before it ever reaches the recorder — a
        // tampered id can never settle someone else's row or one already paid.
        $offer = $options->forUser($request->user(), $target);
        $selection = [
            'weekly_timesheet_ids' => $this->intersectIds($offer['weekly_timesheets'], $request->validated('weekly_timesheet_ids', [])),
            'employee_weekly_timesheet_ids' => $this->intersectIds($offer['employee_weekly_timesheets'], $request->validated('employee_weekly_timesheet_ids', [])),
            'paid_expense_ids' => $this->intersectIds($offer['paid_expenses'], $request->validated('paid_expense_ids', [])),
            'reimbursement_expense_ids' => $this->intersectIds($offer['reimbursement_expenses'], $request->validated('reimbursement_expense_ids', [])),
        ];

        $total = $options->totalForSelection($target, $selection);

        if ($total <= 0) {
            return response()->json([
                'message' => 'Payment needs to be greater than $0.00',
                'errors' => ['payment_total' => ['Payment needs to be greater than $0.00']],
            ], 422);
        }

        $check = $recorder->record(
            $request->user(),
            $target,
            $selection,
            (string) $request->validated('date'),
            $request->validated('check_type'),
            $request->filled('check_number') ? (int) $request->validated('check_number') : null,
            $request->filled('bank_account_id') ? (int) $request->validated('bank_account_id') : null,
            $request->filled('paid_by') ? (int) $request->validated('paid_by') : null,
            $request->validated('invoice'),
        );

        if (! $check) {
            return response()->json(['data' => ['check' => null]]);
        }

        $check->load(['bank_account.bank', 'vendor', 'user']);

        return response()->json(['data' => ['check' => (new CheckResource($check))->withDetail()->toArray($request)]], 201);
    }

    /** @return list<int> */
    private function intersectIds(Collection $offered, mixed $requested): array
    {
        $requestedIds = collect((array) $requested)->map(fn ($id) => (int) $id);

        return $offered->pluck('id')->intersect($requestedIds)->values()->all();
    }

    /** @return list<array{id: int, label: string, next_check_number: int|null}> */
    private function bankAccountsArray(Collection $bankAccounts, TimesheetPaymentOptions $options): array
    {
        return $bankAccounts->map(fn (BankAccount $account) => [
            'id' => $account->id,
            'label' => $account->getNameAndType(),
            'next_check_number' => $options->nextCheckNumber($account),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function timesheetRow(Timesheet $timesheet, bool $selectedByDefault, bool $withPayer = false): array
    {
        return [
            'id' => $timesheet->id,
            'date' => $timesheet->date->format('Y-m-d'),
            'amount' => (float) $timesheet->amount,
            'hours' => (float) $timesheet->hours,
            'payer' => $withPayer ? trim(($timesheet->user->first_name ?? '').' '.($timesheet->user->last_name ?? '')) : null,
            'selected' => $selectedByDefault,
        ];
    }

    /** @return array<string, mixed> */
    private function expenseRow(Expense $expense, bool $selectedByDefault): array
    {
        return [
            'id' => $expense->id,
            'date' => $expense->date?->format('Y-m-d'),
            'amount' => (float) $expense->amount,
            'note' => $expense->note,
            'selected' => $selectedByDefault,
        ];
    }

    /** The target team member: 404 when they don't exist anywhere (mirrors TimesheetController's visibleTimesheet()'s 404-first pattern). */
    private function visibleUser(int $id): User
    {
        $user = User::withoutGlobalScopes()->find($id);
        abort_unless($user, 404);

        return $user;
    }
}
