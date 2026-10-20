<?php

namespace App\Http\Resources\Api\App;

use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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

        return $row + [
            'contacts' => $isRetail ? [] : $this->contacts($vendor),
            'upcoming_tasks' => $isRetail ? [] : $this->upcomingTasks($vendor),
            // Insurance/vendor-document status: only Sub/DBA vendors carry
            // documents at all, and only an admin (VendorDocPolicy) sees them
            // — matches show.blade's @can('update', $vendor) wrapping the
            // vendor-docs-card for those two business types.
            'documents' => ($this->canManage && in_array($vendor->business_type, ['Sub', 'DBA'], true))
                ? $this->documents($vendor)
                : null,
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
     * The next 10 scheduled tasks for this vendor — same window as
     * VendorTaskList's groupedTasks(), flattened to a simple upcoming list
     * (the web's 8-day grid doesn't translate to a phone card).
     *
     * @return list<array{id: int, title: string, project: array{id: int, short_address: ?string}, date: string, start_time: ?string, end_time: ?string}>
     */
    private function upcomingTasks(Vendor $vendor): array
    {
        $viewerVendorId = (int) auth()->user()?->vendor?->id;

        $tasks = Task::withTrashed()
            ->where('vendor_id', $vendor->id)
            ->where('belongs_to_vendor_id', $viewerVendorId)
            ->where(function ($query): void {
                $query->where('type', '!=', 'Reminder')->orWhereNull('type');
            })
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->where('end_date', '>=', today())
            ->with('project:id,project_name,address,address_2,city,state,zip_code')
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->limit(10)
            ->get();

        return $tasks->filter(fn (Task $task) => $task->project !== null)->map(function (Task $task): array {
            $dateKey = $task->start_date->format('Y-m-d');
            $daySettings = data_get($task->options, "time_settings.{$dateKey}", null);
            $usesTime = (bool) data_get($daySettings, 'use_time', false);

            return [
                'id' => $task->id,
                'title' => $task->title,
                'project' => ['id' => $task->project->id, 'short_address' => $task->project->short_address],
                'date' => $dateKey,
                'start_time' => $usesTime ? (data_get($daySettings, 'start_time') ?: null) : null,
                'end_time' => $usesTime ? (data_get($daySettings, 'end_time') ?: null) : null,
            ];
        })->values()->all();
    }

    /**
     * One row per document type (the latest of each) — mirrors
     * VendorDocsCard::render()'s grouping, with the same aggregated
     * verification summary the card's "Verified" column reads
     * (VendorDoc::getVerificationSummaryAttribute()).
     *
     * @return list<array{id: int, type: string, type_label: string, expiration_date: ?string, number: ?string, verified: bool, verified_at: ?string, alert: bool, tip: string}>
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
            ];
        })->values()->all();
    }
}
