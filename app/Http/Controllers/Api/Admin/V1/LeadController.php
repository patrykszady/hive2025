<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Management API for ss-systems' Livewire\Admin\ContactSubmissions screen
 * ("Leads"). hive.contractors' marketing site has no contact form at all
 * (see AnalyticsController's docblock) — there is no App\Models\Lead row
 * this could ever read, and App\Models\Lead itself is deliberately off
 * limits here: it is the general contractor's OWN customer CRM pipeline
 * (routes/api.php's /api/v1/leads, LeadsController), never Hive's own
 * sign-up funnel — see DashboardStatsController's docblock for the same
 * boundary.
 *
 * A "lead" for Hive is a new sign-up: someone who went through the public
 * `route('registration')` flow (App\Livewire\Entry\Registration). That
 * flow is the ONLY code path that ever writes users.`registration`
 * (Registration::updateRegistrationStep()/markAsRegistered() — every other
 * way a User row gets created, e.g. App\Livewire\Forms\UserForm::store()
 * when an existing company adds a team member, leaves it null) — so
 * `whereNotNull('registration')` is an exact, exclusive marker for "came
 * through the sign-up funnel", independent of whether they finished it.
 * A still-mid-verification row (phone confirmed, email/password not yet
 * set) counts too: it is exactly the kind of "reached out, hasn't closed
 * the loop" row a Leads screen exists to surface, the same way an
 * unanswered contact-form submission would.
 *
 * name/email/phone come from the User row itself. `name` prefers the
 * company name when a Vendor is already attached (primary_vendor_id) —
 * that only happens once Patryk has manually onboarded the business behind
 * a sign-up, so most rows show the person who signed up instead, which is
 * exactly who to follow up with. `message` is always null: the sign-up
 * flow asks only for a phone, an email and a name — no plan/size/trade
 * question exists to answer it from (contrast dawnsellshomes' Lead::message,
 * a real form field). `status` is always 'legitimate' — an account is
 * never spam-scored — and `source` is always 'Sign-up', hive's one and
 * only lead channel.
 */
class LeadController extends Controller
{
    use BuildsApiResponses;

    public const SOURCE = 'Sign-up';

    public const READ_ONLY_MESSAGE = "Sign-ups are accounts; they can't be marked or deleted here.";

    public function index(Request $request): JsonResponse
    {
        $query = $this->baseQuery();

        if ($search = $request->string('search')->toString()) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('cell_phone', 'like', "%{$search}%");
            });
        }

        // Every row here is already 'legitimate' (see the class docblock) —
        // 'spam' therefore matches nothing, and 'real'/'legitimate'/
        // 'pending'/'' all mean "no filter", the same convention
        // dawnsellshomes' LeadController uses for its own single-status site.
        match ($request->string('status')->toString()) {
            'spam' => $query->whereRaw('1 = 0'),
            default => null,
        };

        $this->applyDateRange($query, $request->string('date_range')->toString());

        // `source` is accepted for shape-parity with the shared screen's
        // filter row, but Hive has exactly one channel (self::SOURCE) so it
        // never narrows the result set, whatever value is passed.

        $query->orderByDesc('created_at');

        $paginator = $query->paginate($this->perPage($request));

        return $this->paginatedResponse($paginator, fn (User $user) => $this->toApiArray($user));
    }

    public function show(int $lead): JsonResponse
    {
        $user = $this->baseQuery()->findOrFail($lead);

        return $this->itemResponse($this->toApiArray($user));
    }

    /** Sign-ups are accounts, not moderation queue rows — there is no status to set. */
    public function updateStatus(int $lead): JsonResponse
    {
        return response()->json(['message' => self::READ_ONLY_MESSAGE], 405);
    }

    /** Sign-ups are accounts — deleting the "lead" would mean deleting the person's login. */
    public function destroy(int $lead): JsonResponse
    {
        return response()->json(['message' => self::READ_ONLY_MESSAGE], 405);
    }

    public function stats(): JsonResponse
    {
        $total = $this->baseQuery()->count();

        return $this->itemResponse([
            'total' => $total,
            'today' => $this->baseQuery()->whereDate('created_at', today())->count(),
            'week' => $this->baseQuery()->where('created_at', '>=', now()->subWeek())->count(),
            'month' => $this->baseQuery()->where('created_at', '>=', now()->subMonth())->count(),
            // Never spam — an account sign-up has no spam-score concept.
            'spam' => 0,
            'new' => $this->baseQuery()->where('created_at', '>=', now()->subDays(7))->count(),
            'sources' => [
                ['source' => self::SOURCE, 'count' => $total],
            ],
            'top_cities' => [],
            'traffic_sources' => [],
        ]);
    }

    /** Every User row the public sign-up flow ever touched — see the class docblock. */
    protected function baseQuery(): Builder
    {
        return User::query()->with('vendor')->whereNotNull('registration');
    }

    /** 'all' (default, no-op) | 'today' | 'week' | 'month'. */
    protected function applyDateRange(Builder $query, string $range): void
    {
        match ($range) {
            'today' => $query->whereDate('created_at', today()),
            'week' => $query->where('created_at', '>=', now()->subWeek()),
            'month' => $query->where('created_at', '>=', now()->subMonth()),
            default => null,
        };
    }

    /**
     * users.cell_phone is normalized to digits-only by User's own cellPhone()
     * mutator — format it back to (XXX) XXX-XXXX for display, the same shape
     * the sign-up form itself asks for and Vendor::businessPhone() renders
     * elsewhere in this app. Anything other than a clean 10-digit US number
     * (a placeholder contact, an odd import) is passed through as-is.
     */
    protected function formattedPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) === 10
            ? sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6))
            : $phone;
    }

    /** @return array<string, mixed> */
    protected function toApiArray(User $user): array
    {
        $vendor = $user->vendor;
        $companyName = ($vendor && $vendor->name !== 'NO VENDOR') ? $vendor->name : null;
        $personName = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return [
            'id' => $user->id,
            'name' => $companyName ?: ($personName !== '' ? $personName : $user->email),
            'email' => $user->email,
            'phone' => $this->formattedPhone($user->cell_phone),
            // Always null — see the class docblock.
            'message' => null,
            'status' => 'legitimate',
            'source' => self::SOURCE,
            'referrer' => null,
            'utm_source' => null,
            'utm_campaign' => null,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
