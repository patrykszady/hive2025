<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreLeadRequest;
use App\Http\Requests\Api\App\UpdateLeadRequest;
use App\Http\Requests\Api\App\UpdateLeadStatusRequest;
use App\Http\Resources\Api\App\LeadResource;
use App\Livewire\Leads\LeadsSidebarBadge;
use App\Models\Lead;
use App\Services\LeadContactProvisioner;
use App\Services\Leads\LeadDuplicateFinder;
use App\Services\Leads\LeadScheduleLinkTexter;
use App\Services\UrlShortener;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The app's Leads tab — Admin-only, exactly like the web: LeadPolicy's
 * viewAny/view both require vendor_role === 'Admin', and every lead action
 * on the web (status change, bulk delete, the create/edit modal) sits
 * behind that same check rather than separate create/update/delete gates
 * — LeadPolicy leaves those as unimplemented stubs, so every write in
 * App\Livewire\Leads\LeadsIndex authorizes 'viewAny' instead. This
 * controller mirrors that exactly: every action below authorizes
 * 'viewAny', never 'create'/'update'/'delete'.
 *
 * Left web-only (see the PR notes): the Message composer (template-based
 * email send, duplicate-contact merge UI, booking an exact consult time) —
 * a desktop-sized paperwork flow the task scopes out in favor of the app's
 * existing call/text flows. Booking a consult (the one path that creates a
 * Project and flips a lead to Won) is therefore also web-only; the app
 * changes a lead's status and offers the scheduling link, same as the web
 * lets anyone reach a lead's own picker.
 */
class LeadController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $origin = trim((string) $request->query('origin', ''));
        $statuses = array_values(array_filter((array) $request->query('statuses', [])));
        $search = trim((string) $request->query('search', ''));

        $query = Lead::with(['user.clients', 'last_status'])
            ->withCount('feedback')
            ->when($origin !== '', fn ($q) => $q->where('origin', $origin))
            ->when($statuses !== [], fn ($q) => $q->whereLatestStatus($statuses))
            ->when($search !== '', function ($q) use ($search) {
                $term = mb_strtolower($search);
                $q->where(function ($inner) use ($term) {
                    $inner->whereRaw('LOWER(lead_data) like ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(notes) like ?', ["%{$term}%"]);
                });
            })
            ->orderByDesc('date')
            ->orderByDesc('id');

        $page = $query->cursorPaginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Lead $lead) => (new LeadResource($lead))->toArray($request))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /**
     * Leads still awaiting a reply — the same count
     * App\Livewire\Leads\LeadsSidebarBadge shows the web sidebar, read
     * straight from its cache so the two never disagree. Mirrors its
     * render(): 0 (not a 403) for anyone who can't view leads, since the
     * web simply hides the badge rather than erroring.
     */
    public function badge(Request $request): JsonResponse
    {
        if (! $request->user()->can('viewAny', Lead::class)) {
            return response()->json(['count' => 0]);
        }

        return response()->json(['count' => LeadsSidebarBadge::newLeadCount()]);
    }

    public function show(Request $request, int $lead): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $found = $this->findOrFail($lead);

        return response()->json(['data' => (new LeadResource($found))->withDetail()->toArray($request)]);
    }

    /**
     * Create a lead by hand — the person who called instead of emailing or
     * filling the website form. Mirrors LeadCreate::save(): a duplicate
     * contact/lead on file is reported back (409) instead of silently
     * creating a twin; resubmitting with `force: true` goes through anyway,
     * matching the web's "Create anyway" button.
     */
    public function store(StoreLeadRequest $request, LeadDuplicateFinder $finder): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $user = $request->user();
        $vendorId = (int) $user->vendor->id;

        $email = (string) $request->validated('email');
        $phone = (string) $request->validated('phone');

        if (! $request->boolean('force')) {
            $match = $finder->find($email, $phone, $vendorId);

            if ($match !== null) {
                // 'message' (ApiError.message on the app) carries the human
                // label for a plain alert; 'duplicate' is the structured
                // match for a client that wants to act on it directly.
                return response()->json([
                    'message' => $match['label'].' — create this lead anyway?',
                    'duplicate' => $match,
                ], 409);
            }
        }

        $lead = Lead::create([
            'date' => now(),
            'origin' => $request->validated('origin') ?: 'Manual',
            'lead_data' => array_filter([
                'name' => trim((string) $request->validated('full_name')),
                'email' => trim($email) ?: null,
                'phone' => preg_replace('/\D/', '', $phone) ?: null,
                'address' => $request->validated('address'),
                'message' => $request->validated('message'),
                'consult_notice_hours' => $request->boolean('consult_within_the_hour') ? 1 : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'belongs_to_vendor_id' => $vendorId,
            'created_by_user_id' => $user->id,
        ]);

        // Same parity story as email leads: without the status row the lead
        // has no pipeline stage; without provisioning it has no contact.
        $lead->statuses()->create([
            'title' => 'New',
            'belongs_to_vendor_id' => $vendorId,
        ]);

        try {
            app(LeadContactProvisioner::class)->provision($lead->fresh());
        } catch (\Throwable $e) {
            Log::warning('App lead: contact provisioning failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }

        LeadsSidebarBadge::bustBadgeCache($vendorId);

        return response()->json([
            'data' => (new LeadResource($this->findOrFail($lead->id)))->withDetail()->toArray($request),
        ], 201);
    }

    /**
     * The lead's contact/address fields and origin — mirrors
     * App\Livewire\Leads\LeadCreate::edit() apart from the status write
     * (see updateStatus() below for that, kept as its own call so the app
     * never writes a duplicate status row the way edit()'s combined save
     * can).
     */
    public function update(UpdateLeadRequest $request, int $lead): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $found = $this->findOrFail($lead);

        foreach (['address', 'city', 'state', 'zip', 'phone', 'email'] as $field) {
            if ($request->has($field)) {
                $found->lead_data[$field] = $request->validated($field);
            }
        }

        if ($request->has('origin')) {
            $found->origin = $request->validated('origin');
        }

        $found->save();

        return response()->json(['data' => (new LeadResource($this->findOrFail($lead)))->withDetail()->toArray($request)]);
    }

    /** Mirrors App\Livewire\Leads\LeadsIndex::updateLeadStatus(). */
    public function updateStatus(UpdateLeadStatusRequest $request, int $lead): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $found = $this->findOrFail($lead);
        $found->setStatus($request->validated('status'));

        LeadsSidebarBadge::bustBadgeCache((int) $found->belongs_to_vendor_id);

        return response()->json(['data' => (new LeadResource($this->findOrFail($lead)))->withDetail()->toArray($request)]);
    }

    /** Mirrors App\Livewire\Leads\LeadCreate::remove()/confirmRemove(). */
    public function destroy(Request $request, int $lead): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $found = $this->findOrFail($lead);

        if ($this->hasReplied($found)) {
            return response()->json([
                'message' => 'This lead has a reply on record and cannot be removed.',
            ], 422);
        }

        $found->deleteWithOrphans();

        LeadsSidebarBadge::bustBadgeCache((int) $found->belongs_to_vendor_id);

        return response()->json(['message' => 'Lead deleted.']);
    }

    /** Mirrors LeadCreate::copyScheduleLink(): hand back the lead's own scheduling link. */
    public function scheduleLink(Request $request, int $lead, UrlShortener $shortener): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $found = $this->findOrFail($lead);

        return response()->json(['url' => $shortener->shorten($found->availabilityUrl())]);
    }

    /** Mirrors LeadCreate::textScheduleLink() via App\Services\Leads\LeadScheduleLinkTexter. */
    public function textScheduleLink(Request $request, int $lead, LeadScheduleLinkTexter $texter): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $found = $this->findOrFail($lead);
        $result = $texter->text($found, $found->resolveClient(), $request->user());

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /** A lead the current vendor may see: 404 when it doesn't exist (LeadScope already keeps this tenant-only). */
    private function findOrFail(int $id): Lead
    {
        $lead = Lead::with(['user.clients', 'last_status', 'feedback', 'statuses'])->find($id);
        abort_unless($lead, 404);

        return $lead;
    }

    /** Mirrors LeadCreate::hasReplied() — a bounced reply doesn't count as one, so the lead may still be removed. */
    private function hasReplied(Lead $lead): bool
    {
        if (($lead->last_status?->title ?? null) !== 'Replied') {
            return false;
        }

        $outcome = \App\Models\EmailTracking::withoutGlobalScopes()
            ->where('lead_id', $lead->id)
            ->whereIn('event_type', ['bounced', 'delivered', 'opened', 'clicked'])
            ->orderByDesc('event_at')
            ->orderByDesc('id')
            ->value('event_type');

        return $outcome !== 'bounced';
    }
}
