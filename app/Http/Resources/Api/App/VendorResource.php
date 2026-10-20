<?php

namespace App\Http\Resources\Api\App;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Services\Dashboard\AppHomeAgenda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * A vendor as the app's Vendors tab shows it. With withDetail() it adds
 * everything the detail screen needs beyond the list row — see
 * VendorController::show(). Mirrors resources/views/livewire/vendors/show.blade.php
 * and vendor-details.blade.php's visibility rules exactly, including which
 * sections are Retail-only, non-Retail-only, or admin-only.
 *
 * @mixin \App\Models\Vendor
 */
class VendorResource extends JsonResource
{
    protected bool $detail = false;

    protected bool $canManage = false;

    protected bool $canPay = false;

    protected ?string $since = null;

    /**
     * Whether the viewer may manage this vendor (VendorPolicy::update) — the
     * same gate the web's YTD-paid figure and vendor-docs card use. Passed
     * in rather than re-checked here so the controller's Gate call (which
     * already needs the authenticated user) is the only one made per row.
     */
    public function canManage(bool $canManage): static
    {
        $this->canManage = $canManage;

        return $this;
    }

    /**
     * Whether the viewer may write a payment check to this vendor
     * (CheckPolicy::create) — a different, narrower gate than canManage():
     * an Admin whose own vendor is 1099 can manage records but may never
     * write a check (CheckPolicy::create's business_type check), exactly
     * like VendorPaymentCreate::mount()'s own authorize() call.
     */
    public function canPay(bool $canPay): static
    {
        $this->canPay = $canPay;

        return $this;
    }

    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * The "Since" column on the web's Vendors table — when this vendor was
     * attached to the viewer's directory (the `vendors_vendor` pivot's own
     * `created_at`), as a raw datetime string ready for the client to
     * format/diff. Null for a vendor with no pivot row (viewing one's own
     * company, which is never attached to itself).
     */
    public function since(?string $since): static
    {
        $this->since = $since;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Vendor $vendor */
        $vendor = $this->resource;
        $isRetail = $vendor->business_type === 'Retail';
        $viewerVendorId = (int) $request->user()?->vendor?->id;

        // VendorDetails.blade's subheading: YTD Paid only for someone who can
        // manage vendors, and never for a vendor's own company looking at
        // itself (there is nothing to pay).
        $showYtdPaid = $this->canManage && $vendor->id !== $viewerVendorId;

        $row = [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'business_name' => $vendor->business_name,
            'business_type' => $vendor->business_type,
            'is_own_company' => $vendor->id === $viewerVendorId,
            'address' => $isRetail ? null : [
                'line1' => $vendor->address,
                'line2' => $vendor->address_2,
                'city' => $vendor->city,
                'state' => $vendor->state,
                'zip' => $vendor->zip_code,
            ],
            'business_phone' => $isRetail ? null : $vendor->business_phone,
            'business_email' => $isRetail ? null : $vendor->business_email,
            'business_website' => $isRetail ? null : $vendor->business_website,
            'ytd_paid' => $showYtdPaid ? (float) $vendor->ytd_expense_sum : null,
            // The Vendors index table's "Since" column — null when this
            // vendor was never attached via the pivot (one's own company).
            'since' => $this->since,
            'can_manage' => $this->canManage,
            // vendor-details.blade's "Make Payment" button: never your own
            // company, never a Retail vendor, and (unlike the web's button,
            // which shows unconditionally and lets the destination page
            // 403) only when CheckPolicy::create genuinely allows it.
            'can_pay' => $this->canPay && $vendor->id !== $viewerVendorId && ! $isRetail,
            'web_path' => '/vendors/'.$vendor->id,
        ];

        if (! $this->detail) {
            return $row;
        }

        $tasks = $isRetail ? null : $this->tasksCard($vendor, $viewerVendorId);

        return $row + [
            'contacts' => $isRetail ? [] : $this->contacts($vendor),
            'task_count' => $tasks['task_count'] ?? 0,
            'pending_tasks' => $tasks['pending_tasks'] ?? [],
            'upcoming_tasks' => $tasks['upcoming_tasks'] ?? [],
            'later_tasks' => $tasks['later_tasks'] ?? [],
            // Insurance/vendor-document status: only Sub/DBA vendors carry
            // documents at all, and only an admin (VendorDocPolicy) sees them
            // — matches show.blade's @can('update', $vendor) wrapping the
            // vendor-docs-card for those two business types.
            'documents' => ($this->canManage && in_array($vendor->business_type, ['Sub', 'DBA'], true))
                ? $this->documents($vendor)
                : null,
            // show.blade's Checks card: any viewer of a non-Retail vendor,
            // when it has at least one check written to it (no @can wrapper
            // on the web — unlike documents/YTD paid, which stay admin-only).
            'payments' => (! $isRetail && $vendor->checks()->where('belongs_to_vendor_id', $viewerVendorId)->exists())
                ? $this->payments($vendor, $viewerVendorId)
                : null,
            // show.blade's Expenses card (expense-index 'vendors.show' view):
            // Retail vendors only, same unrestricted visibility as Checks.
            'expense_summary' => $isRetail ? $this->expenseSummary($vendor, $viewerVendorId) : null,
        ];
    }

    /**
     * Employed team members — mirrors UsersIndex's 'vendors.show' view
     * (full name, phone, email, role for this vendor).
     *
     * @return list<array{id: int, name: string, cell_phone: ?string, email: ?string, role: string}>
     */
    private function contacts(Vendor $vendor): array
    {
        return $vendor->users()->employed()->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => trim($user->first_name.' '.$user->last_name),
                'cell_phone' => $user->cell_phone,
                'email' => $user->email,
                'role' => $user->getRoleForVendor($vendor->id),
            ])
            ->values()
            ->all();
    }

    /**
     * The vendor show page's "Tasks" card — mirrors
     * App\Livewire\Vendors\VendorTaskList exactly: $taskCount is the 7-day
     * (today..+6) scheduled count for the card's heading badge,
     * pending_tasks is the "Pending Tasks" accordion (unscheduled tasks),
     * and upcoming_tasks is the scheduled tasks grouped by day across the
     * 8-day window (yesterday..+6) with the same Today/Tomorrow/Yesterday
     * flags the web's upcoming-tasks-list-day.blade.php badges, and
     * later_tasks is the "Later" accordion — tasks beyond that window,
     * grouped by date (App\Livewire\Concerns\HasLaterTasks).
     *
     * @return array{task_count: int, pending_tasks: list<array<string, mixed>>, upcoming_tasks: list<array{date: string, is_today: bool, is_yesterday: bool, is_tomorrow: bool, tasks: list<array<string, mixed>>}>, later_tasks: list<array{date: string, tasks: list<array<string, mixed>>}>}
     */
    private function tasksCard(Vendor $vendor, int $viewerVendorId): array
    {
        $today = Carbon::now(AppHomeAgenda::TIMEZONE)->startOfDay();
        $cutoffStr = $today->copy()->subDay()->format('Y-m-d');
        $windowEndStr = $today->copy()->addDays(6)->format('Y-m-d');
        $todayStr = $today->format('Y-m-d');
        $yesterdayStr = $cutoffStr;
        $tomorrowStr = $today->copy()->addDay()->format('Y-m-d');

        $baseQuery = fn (): Builder => Task::withTrashed()
            ->where('vendor_id', $vendor->id)
            ->where('belongs_to_vendor_id', $viewerVendorId)
            ->where(function (Builder $query): void {
                $query->where('type', '!=', 'Reminder')->orWhereNull('type');
            });

        $taskCount = $baseQuery()
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $windowEndStr)
            ->whereDate('end_date', '>=', $todayStr)
            ->count();

        $scheduled = $baseQuery()
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $windowEndStr)
            ->whereDate('end_date', '>=', $cutoffStr)
            ->with('project:id,project_name,address,address_2,city,state,zip_code')
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get()
            ->filter(fn (Task $task) => $task->project !== null);

        $this->primeTaskUsers($scheduled);

        $grouped = collect();
        foreach ($scheduled as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);
            $dates = $selectedDates !== [] ? $selectedDates : [$task->start_date->format('Y-m-d')];

            foreach ($dates as $dateStr) {
                if ($dateStr >= $cutoffStr && $dateStr <= $windowEndStr) {
                    $grouped[$dateStr] ??= collect();
                    $grouped[$dateStr]->push($task);
                }
            }
        }

        $upcomingTasks = $grouped->sortKeys()->map(function (Collection $dayTasks, string $dateStr) use ($todayStr, $yesterdayStr, $tomorrowStr): array {
            $sorted = $dayTasks->sortBy(function (Task $task) use ($dateStr) {
                $startTime = (string) data_get($task->options, "time_settings.$dateStr.start_time", '');
                $usesTime = (bool) data_get($task->options, "time_settings.$dateStr.use_time", false);

                return $usesTime && $startTime !== '' ? '0_'.$startTime : '1';
            })->values();

            return [
                'date' => $dateStr,
                'is_today' => $dateStr === $todayStr,
                'is_yesterday' => $dateStr === $yesterdayStr,
                'is_tomorrow' => $dateStr === $tomorrowStr,
                'tasks' => $sorted->map(fn (Task $task) => $this->taskRow($task, $dateStr))->values()->all(),
            ];
        })->values()->all();

        $pending = $baseQuery()
            ->whereNull('start_date')
            ->with('project:id,project_name,address,address_2,city,state,zip_code')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Task $task) => $task->project !== null);

        $this->primeTaskUsers($pending);

        // The "Later" accordion — tasks starting after the window, grouped by
        // their selected dates beyond it (App\Livewire\Concerns\HasLaterTasks).
        $laterScheduled = $baseQuery()
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '>', $windowEndStr)
            ->with('project:id,project_name,address,address_2,city,state,zip_code')
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get()
            ->filter(fn (Task $task) => $task->project !== null);

        $this->primeTaskUsers($laterScheduled);

        $laterGrouped = collect();
        foreach ($laterScheduled as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);
            $dates = $selectedDates !== [] ? $selectedDates : [$task->start_date->format('Y-m-d')];

            foreach ($dates as $dateStr) {
                if ($dateStr > $windowEndStr) {
                    $laterGrouped[$dateStr] ??= collect();
                    $laterGrouped[$dateStr]->push($task);
                }
            }
        }

        return [
            'task_count' => $taskCount,
            'pending_tasks' => $pending->map(fn (Task $task) => $this->taskRow($task, null))->values()->all(),
            'upcoming_tasks' => $upcomingTasks,
            'later_tasks' => $laterGrouped->sortKeys()->map(fn (Collection $dayTasks, string $dateStr): array => [
                'date' => $dateStr,
                'tasks' => $dayTasks->map(fn (Task $task) => $this->taskRow($task, $dateStr))->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array{id: int, title: string, project: array{id: int, short_address: ?string}, date: ?string, start_time: ?string, end_time: ?string, users: list<string>}
     */
    private function taskRow(Task $task, ?string $dateKey): array
    {
        $dateKey ??= $task->start_date?->format('Y-m-d');
        $daySettings = $dateKey ? data_get($task->options, "time_settings.{$dateKey}", null) : null;
        $usesTime = (bool) data_get($daySettings, 'use_time', false);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'project' => ['id' => $task->project->id, 'short_address' => $task->project->short_address],
            'date' => $dateKey,
            'start_time' => $usesTime ? (data_get($daySettings, 'start_time') ?: null) : null,
            'end_time' => $usesTime ? (data_get($daySettings, 'end_time') ?: null) : null,
            'users' => $task->users->map(fn (User $user): string => trim($user->first_name.' '.$user->last_name))->values()->all(),
        ];
    }

    /**
     * Attaches each task's assigned User models onto its "users" relation —
     * VendorTaskList::hydrateAssignedUsers() exactly.
     *
     * @param  Collection<int, Task>  $tasks
     */
    private function primeTaskUsers(Collection $tasks): void
    {
        $allUserIds = $tasks->pluck('user_ids')->flatten()->filter()->unique()->values();

        $usersById = $allUserIds->isNotEmpty()
            ? User::query()->whereIn('id', $allUserIds)->get()->keyBy('id')
            : collect();

        foreach ($tasks as $task) {
            $assignedUsers = collect($task->user_ids ?? [])
                ->map(fn ($userId) => $usersById->get($userId))
                ->filter()
                ->values();

            $task->setRelation('users', $assignedUsers);
        }
    }

    /**
     * One row per document type (the latest of each) — mirrors
     * VendorDocsCard::render()'s grouping, with the same aggregated
     * verification summary the card's "Verified" column reads
     * (VendorDoc::getVerificationSummaryAttribute()).
     *
     * @return list<array{id: int, type: string, type_label: string, expiration_date: ?string, number: ?string, verified: bool, verified_at: ?string, alert: bool, tip: string, file_url: ?string}>
     */
    private function documents(Vendor $vendor): array
    {
        $docs = VendorDoc::withoutGlobalScopes()
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('expiration_date')
            ->get()
            ->groupBy(fn (VendorDoc $doc) => strtolower((string) ($doc->getRawOriginal('type') ?? $doc->type)))
            ->map(fn ($group) => $group->first())
            ->values();

        return $docs->map(function (VendorDoc $doc): array {
            $verification = $doc->verification_summary;

            return [
                'id' => $doc->id,
                'type' => (string) $doc->getRawOriginal('type'),
                'type_label' => $doc->type_label,
                'expiration_date' => $doc->expiration_date?->toDateString(),
                'number' => $doc->number,
                'verified' => (bool) ($verification['verified'] ?? false),
                'verified_at' => $verification['verified_at'] ?? null,
                'alert' => (bool) ($verification['alert'] ?? false),
                'tip' => (string) ($verification['tip'] ?? ''),
                // The web's per-row "View" link (vendor-docs/_doc-row.blade.php:5-12) —
                // same signed file route VendorDocResource gives the Manage
                // hub's own Vendor Documents screen.
                'file_url' => $doc->doc_filename
                    ? URL::temporarySignedRoute('vendor-docs.file.signed', now()->addHours(24), ['vendorDoc' => $doc->id])
                    : null,
            ];
        })->values()->all();
    }

    /**
     * The most recent 15 checks written to this (non-Retail) vendor by the
     * viewer's own company — show.blade's `<livewire:checks.checks-index
     * :vendor="$vendor->id" :view="'vendors.show'" />` card, condensed to a
     * list (the full searchable/paginated Checks index stays web-only).
     * Scoped to belongs_to_vendor_id like every other tenant-scoped query
     * here — a sub shared by two GCs never shows one company's checks to
     * the other.
     *
     * @return list<array{id: int, date: ?string, amount: float, check_number: ?int, status: array{label: string, color: string}, web_path: string}>
     */
    private function payments(Vendor $vendor, int $viewerVendorId): array
    {
        return Check::where('vendor_id', $vendor->id)
            ->where('belongs_to_vendor_id', $viewerVendorId)
            ->orderByDesc('date')
            ->limit(15)
            ->get()
            ->map(fn (Check $check): array => [
                'id' => $check->id,
                'date' => $check->date?->toDateString(),
                'amount' => (float) $check->amount,
                'check_number' => $check->check_number,
                'status' => ['label' => $check->status, 'color' => $check->statusColor],
                'web_path' => '/checks/'.$check->id,
            ])
            ->values()
            ->all();
    }

    /**
     * Retail vendor expense summary — show.blade's
     * `<livewire:expenses.expense-index :expense_vendor="$vendor->id"
     * :view="'vendors.show'" />` card condensed to a YTD total plus the 15
     * most recent rows (the full searchable/paginated Expenses index, with
     * its Meilisearch filters, stays web-only). Scoped to
     * belongs_to_vendor_id, same reasoning as payments() above.
     *
     * @return array{ytd_total: float, recent: list<array{id: int, date: ?string, amount: float, category: ?string, project: ?string}>}
     */
    private function expenseSummary(Vendor $vendor, int $viewerVendorId): array
    {
        $base = Expense::where('vendor_id', $vendor->id)
            ->where('belongs_to_vendor_id', $viewerVendorId);

        $ytdTotal = (clone $base)->where('date', '>=', today()->subYear())->sum('amount');

        $recent = (clone $base)
            ->with('category:id,friendly_primary', 'project:id,project_name,address')
            ->orderByDesc('date')
            ->limit(15)
            ->get()
            ->map(fn (Expense $expense): array => [
                'id' => $expense->id,
                'date' => $expense->date?->toDateString(),
                'amount' => (float) $expense->amount,
                'category' => $expense->category?->friendly_primary,
                'project' => $expense->project?->name,
            ])
            ->values()
            ->all();

        return ['ytd_total' => (float) $ytdTotal, 'recent' => $recent];
    }
}
