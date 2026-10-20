<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\BulkLeadIdsRequest;
use App\Http\Requests\Api\App\BulkUpdateLeadStatusRequest;
use App\Http\Requests\Api\App\PreviewLeadReplyRequest;
use App\Http\Requests\Api\App\ProposeLeadConsultDateRequest;
use App\Http\Requests\Api\App\SendConsultInviteRequest;
use App\Http\Requests\Api\App\SendLeadReplyRequest;
use App\Http\Requests\Api\App\StoreLeadRequest;
use App\Http\Requests\Api\App\UpdateLeadRequest;
use App\Http\Requests\Api\App\UpdateLeadStatusRequest;
use App\Http\Resources\Api\App\LeadResource;
use App\Livewire\Leads\LeadsSidebarBadge;
use App\Models\Lead;
use App\Services\LeadContactProvisioner;
use App\Services\Leads\LeadDuplicateFinder;
use App\Services\Leads\LeadReplyComposer;
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
 * The Message composer (replyOptions/previewReply/reply/sendConsultInvite/
 * proposeDate below) and leads-list bulk actions (bulkStatus/
 * bulkDeleteImpact/bulkDelete) delegate to
 * App\Services\Leads\LeadReplyComposer — the same service
 * App\Livewire\Leads\LeadCreate's Message tab calls — so sending, booking a
 * consult and the companion text are byte-for-byte identical to the web.
 * Duplicate-contact detection already happens in store() above via
 * App\Services\Leads\LeadDuplicateFinder.
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

    /** The Origin filter's options — mirrors App\Livewire\Leads\LeadsIndex::origins() exactly. */
    public function origins(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $origins = Lead::query()
            ->whereNotNull('origin')
            ->where('origin', '!=', '')
            ->distinct()
            ->orderBy('origin')
            ->pluck('origin')
            ->all();

        return response()->json(['data' => $origins]);
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

    /**
     * Mirrors LeadCreate::updatedConsultWithinTheHour(): lets this lead's
     * own homeowner pick a consult slot as soon as an hour from now on the
     * public picker, instead of the usual three days.
     */
    public function updateConsultWithinTheHour(Request $request, int $lead): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $found = $this->findOrFail($lead);
        $data = $found->lead_data;

        if ($request->boolean('enabled')) {
            $data['consult_notice_hours'] = 1;
        } else {
            unset($data['consult_notice_hours']);
        }

        $found->lead_data = $data;
        $found->saveQuietly();

        return response()->json(['data' => (new LeadResource($found->fresh()))->withDetail()->toArray($request)]);
    }

    /** Mirrors LeadCreate::copyScheduleLink(): hand back the lead's own scheduling link. */
    public function scheduleLink(Request $request, int $lead, UrlShortener $shortener): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $found = $this->findOrFail($lead);

        return response()->json(['url' => $shortener->shorten($found->availabilityUrl())]);
    }

    /**
     * Mirrors LeadCreate::textScheduleLink() via
     * App\Services\Leads\LeadScheduleLinkTexter. Always 200: "no phone on
     * file" or "awaiting their START reply" are business outcomes (the same
     * ones the web shows as a warning toast, not a validation error), not a
     * rejection of the request itself — `ok` tells the app which it got.
     */
    public function textScheduleLink(Request $request, int $lead, LeadScheduleLinkTexter $texter): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $found = $this->findOrFail($lead);
        $result = $texter->text($found, $found->resolveClient(), $request->user());

        return response()->json($result);
    }

    /**
     * Everything the Message composer prefills — mirrors
     * App\Livewire\Leads\LeadCreate::prepareEmailComposer() via
     * App\Services\Leads\LeadReplyComposer::composerOptions(), plus the
     * availability picker's current state (slot problems, exact-time
     * chips, the consult already on the calendar) so the app's compose
     * screen opens exactly like the web's Message tab does.
     */
    public function replyOptions(Request $request, int $lead, LeadReplyComposer $composer): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $found = $this->findOrFail($lead);
        $client = $composer->resolveClientForLead($found);
        $fullName = $this->leadFullName($found);
        $availability = $composer->rawAvailability($found);

        $options = $composer->composerOptions(
            $found,
            $client,
            $found->user,
            $fullName,
            $found->origin,
            $request->user(),
            $availability,
            $this->leadMeetingPreference($found),
        );

        return response()->json(['data' => $options + [
            'availability' => $availability,
            'slot_problems' => $composer->slotProblems($availability),
            'has_usable_availability' => $composer->hasUsableAvailability($availability),
            'booked_consult' => $composer->bookedConsult($client, $found, $fullName),
            'consult_project_options' => $composer->consultProjectOptions($client),
            'consult_meeting_type' => $this->leadMeetingPreference($found),
            'consult_within_the_hour' => $found->allowsConsultWithinTheHour(),
            'schedule_link' => $composer->scheduleLink($found),
            'client' => $client ? ['id' => $client->id, 'name' => $client->name] : null,
        ]]);
    }

    /**
     * Recomputes the template-filled subject/body and picker state for the
     * composer's CURRENT selection — mirrors every wire:click on the web's
     * Message tab (insertAvailabilitySlot/selectExactTime/updatedProjectName/
     * the template and From selects) via
     * App\Services\Leads\LeadReplyComposer::preview().
     */
    public function previewReply(PreviewLeadReplyRequest $request, int $lead, LeadReplyComposer $composer): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $found = $this->findOrFail($lead);
        $client = $composer->resolveClientForLead($found);
        $fullName = $this->leadFullName($found);

        $result = $composer->preview($found, $client, $fullName, $request->validated());

        return response()->json(['data' => $result]);
    }

    /**
     * Office-side (re)scheduling: propose any weekday — mirrors
     * App\Livewire\Leads\LeadCreate::updatedProposeDate() via
     * App\Services\Leads\LeadReplyComposer::proposeDate().
     */
    public function proposeDate(ProposeLeadConsultDateRequest $request, int $lead, LeadReplyComposer $composer): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $this->findOrFail($lead);

        $result = $composer->proposeDate($request->validated('availability') ?? [], $request->validated('date'));

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['error'],
                'availability' => $result['availability'],
            ], 422);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Sends the reply email and, with a time selected, books the consult —
     * mirrors App\Livewire\Leads\LeadCreate::send_message() exactly via
     * App\Services\Leads\LeadReplyComposer::send(): the same
     * SendLeadReplyJob, the same Project/Meet task booking, the same
     * companion text, the same status transitions.
     */
    public function reply(SendLeadReplyRequest $request, int $lead, LeadReplyComposer $composer): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $found = $this->findOrFail($lead);
        $client = $composer->resolveClientForLead($found);
        $fullName = $this->leadFullName($found);

        $result = $composer->send($found, $client, $request->user(), $fullName, $request->validated() + [
            'selected_template_id' => $request->validated('template_id'),
            'sender_ip' => $request->ip(),
        ]);

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        LeadsSidebarBadge::bustBadgeCache((int) $found->belongs_to_vendor_id);

        return response()->json(['data' => $result]);
    }

    /**
     * One call: apply the "Consult" lead template and send — mirrors
     * App\Livewire\Leads\LeadCreate::sendConsultInvite().
     */
    public function sendConsultInvite(SendConsultInviteRequest $request, int $lead, LeadReplyComposer $composer): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $found = $this->findOrFail($lead);
        $client = $composer->resolveClientForLead($found);
        $fullName = $this->leadFullName($found);

        $result = $composer->sendConsultInvite($found, $client, $request->user(), $fullName, $request->validated() + [
            'sender_ip' => $request->ip(),
        ]);

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        LeadsSidebarBadge::bustBadgeCache((int) $found->belongs_to_vendor_id);

        return response()->json(['data' => $result]);
    }

    /** Mirrors App\Livewire\Leads\LeadsIndex::bulkSetStatus(). */
    public function bulkStatus(BulkUpdateLeadStatusRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $leads = Lead::whereIn('id', $request->validated('lead_ids'))->get();

        foreach ($leads as $lead) {
            $lead->setStatus($request->validated('status'));
        }

        if ($leads->isNotEmpty()) {
            LeadsSidebarBadge::bustBadgeCache((int) $leads->first()->belongs_to_vendor_id);
        }

        return response()->json(['data' => ['updated' => $leads->count()]]);
    }

    /** Mirrors App\Livewire\Leads\LeadsIndex::bulkDeleteImpact(). */
    public function bulkDeleteImpact(BulkLeadIdsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $leads = Lead::whereIn('id', $request->validated('lead_ids'))->get();

        $clients = [];
        $users = [];
        $holding = [];

        foreach ($leads as $lead) {
            $impact = $lead->deleteImpact();
            array_push($clients, ...$impact['clients']);

            if ($impact['user']) {
                $users[] = $impact['user'];
            }

            if ($impact['schedule_link'] || $impact['booked_consult']) {
                $holding[] = trim((string) ($lead->lead_data['name'] ?? '')) ?: 'Lead #'.$lead->id;
            }
        }

        return response()->json(['data' => [
            'count' => $leads->count(),
            'clients' => array_values(array_unique($clients)),
            'users' => array_values(array_unique($users)),
            'holding' => array_values(array_unique($holding)),
        ]]);
    }

    /** Mirrors App\Livewire\Leads\LeadsIndex::bulkDelete(). */
    public function bulkDelete(BulkLeadIdsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $leads = Lead::whereIn('id', $request->validated('lead_ids'))->get();
        $vendorId = $leads->first()?->belongs_to_vendor_id;

        foreach ($leads as $lead) {
            $lead->deleteWithOrphans();
        }

        if ($vendorId) {
            LeadsSidebarBadge::bustBadgeCache((int) $vendorId);
        }

        return response()->json(['data' => ['deleted' => $leads->count()]]);
    }

    /** The lead's current full name, same resolution LeadCreate::editLead() gives $this->full_name. */
    private function leadFullName(Lead $lead): string
    {
        return $lead->user !== null
            ? (string) $lead->user->full_name
            : (string) ($lead->lead_data['name'] ?? '');
    }

    /** Mirrors LeadCreate::editLead()'s $this->consultMeetingType resolution. */
    private function leadMeetingPreference(Lead $lead): string
    {
        $preference = $lead->lead_data['meeting_preference'] ?? null;

        return in_array($preference, ['in_person', 'virtual'], true) ? $preference : 'in_person';
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
