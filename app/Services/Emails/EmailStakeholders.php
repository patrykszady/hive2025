<?php

namespace App\Services\Emails;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who on the other side of an email is a stakeholder of the company's
 * projects, and which projects they could be writing about: a client user
 * (their client's projects) or a vendor — by a user on it or its business
 * address — attached to the company's projects. The company's own vendor
 * row is never a stakeholder.
 */
class EmailStakeholders
{
    /** Projects nothing is ever filed under. */
    public const CLOSED_STATUS_CODES = [10, 11];

    /** Candidate projects offered to the AI read, newest first. */
    public const MAX_CANDIDATES = 15;

    /** Reply/forward prefixes that are never a client's name. */
    private const SUBJECT_NOISE = ['re', 'fw', 'fwd'];

    /**
     * Open projects whose client a capitalized word in the subject names by
     * last name — suppliers and designers write "Abbey-plumbing delivery"
     * about a job they are not on as a vendor, so the sender alone never
     * finds it (emails 2 and 144, 2026-10-20).
     *
     * @return Collection<int, Project>
     */
    public function namedInSubject(?string $subject, int $companyVendorId): Collection
    {
        preg_match_all("/\\p{Lu}[\\p{L}']{2,}/u", (string) $subject, $matches);

        $words = collect($matches[0])
            ->map(fn (string $word) => mb_strtolower($word))
            ->reject(fn (string $word) => in_array($word, self::SUBJECT_NOISE, true))
            ->unique()
            ->values()
            ->all();

        if ($words === []) {
            return collect();
        }

        $clientIds = Client::withoutGlobalScopes()
            ->whereHas('users', fn ($users) => $users->withoutGlobalScopes()->whereIn(DB::raw('LOWER(last_name)'), $words))
            ->pluck('clients.id');

        if ($clientIds->isEmpty()) {
            return collect();
        }

        return $this->open(Project::withoutGlobalScopes()
            ->whereIn('client_id', $clientIds)
            ->where('belongs_to_vendor_id', $companyVendorId));
    }

    /** Street-type words that may follow a street's name, or stand for it. */
    private const STREET_SUFFIXES = [
        'ln', 'lane', 'dr', 'drive', 'st', 'street', 'ave', 'av', 'avenue', 'ct', 'court', 'rd', 'road',
        'blvd', 'boulevard', 'cir', 'circle', 'pl', 'place', 'ter', 'terr', 'terrace', 'trl', 'trail',
        'pkwy', 'parkway', 'way', 'hwy', 'highway', 'sq', 'square', 'pt', 'point', 'xing', 'crossing',
    ];

    private const DIRECTIONS = ['n', 's', 'e', 'w', 'north', 'south', 'east', 'west', 'ne', 'nw', 'se', 'sw'];

    /**
     * The company's projects whose street address the text names — house
     * number and street ("3154 VIOLET LN", "3154 Violet Lane", "3154
     * Violet"). A building department, a title company or a supplier writes
     * about the house, not the people: the Village of Northbrook's
     * inspection notices for 3154 Violet Ln (PB26-0157) came from
     * NOREPLY@northbrook.il.us, a stranger to every project, and were never
     * kept (Patryk, 2026-10-27). Open projects first; cancelled ones never.
     *
     * @return Collection<int, Project>
     */
    public function namedByAddress(?string $text, int $companyVendorId): Collection
    {
        $text = mb_strtolower(Str::squish(strip_tags((string) $text)));

        if (! preg_match_all('/\b(\d{1,6})\s+[a-z]/u', $text, $numbers) || $numbers[1] === []) {
            return collect();
        }

        $houseNumbers = array_values(array_unique($numbers[1]));

        $projects = Project::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $companyVendorId)
            ->where(function ($query) use ($houseNumbers): void {
                foreach ($houseNumbers as $number) {
                    $query->orWhere('address', 'like', $number.' %');
                }
            })
            ->with(['latestStatus' => fn ($status) => $status->withoutGlobalScopes()])
            ->orderByDesc('id')
            ->get()
            ->reject(fn (Project $project) => in_array((int) $project->latestStatus?->status_code, self::CLOSED_STATUS_CODES, true))
            ->filter(fn (Project $project) => ($pattern = self::addressPattern((string) $project->address)) && preg_match($pattern, $text))
            ->values();

        return $projects;
    }

    /**
     * "3154 Violet Ln" → /\b3154\s+(?:n\.?\s+)?violet\b/: the house number,
     * an optional direction, and the street's first word — the suffix and
     * any unit are left out, since every sender writes those differently.
     */
    public static function addressPattern(string $address): ?string
    {
        $street = mb_strtolower(trim(preg_split('/[,#]|\b(?:unit|apt|suite|ste)\b/i', $address)[0] ?? ''));

        if (! preg_match('/^(\d{1,6})\s+(.+)$/u', $street, $parts)) {
            return null;
        }

        $words = preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $parts[2])) ?: [];
        $words = array_values(array_filter($words, fn ($word) => $word !== ''));
        while ($words !== [] && in_array($words[0], self::DIRECTIONS, true)) {
            array_shift($words);
        }

        $name = $words[0] ?? null;
        if (! $name || in_array($name, self::STREET_SUFFIXES, true) || mb_strlen($name) < 3) {
            return null;
        }

        $directions = implode('|', self::DIRECTIONS);

        return '/\b'.preg_quote($parts[1], '/').'\s+(?:(?:'.$directions.')\.?\s+)?'.preg_quote($name, '/').'\b/u';
    }

    /**
     * @param  list<string>  $addresses  external addresses, the most telling first (the sender, or whom we wrote to)
     * @return array{user: ?User, client_id: ?int, vendor_id: ?int, projects: Collection<int, Project>}|null
     */
    public function resolve(array $addresses, int $companyVendorId): ?array
    {
        $addresses = array_values(array_unique(array_filter(array_map(
            fn ($address) => mb_strtolower(trim((string) $address)),
            $addresses,
        ))));

        if ($addresses === []) {
            return null;
        }

        $users = User::withoutGlobalScopes()
            ->whereIn(DB::raw('LOWER(email)'), $addresses)
            ->get()
            ->keyBy(fn (User $user) => mb_strtolower((string) $user->email));

        $vendors = Vendor::withoutGlobalScopes()
            ->whereIn(DB::raw('LOWER(business_email)'), $addresses)
            ->where('id', '!=', $companyVendorId)
            ->get()
            ->keyBy(fn (Vendor $vendor) => mb_strtolower((string) $vendor->business_email));

        // A homeowner on the email makes it about their job, wherever they
        // are on it: Jenn Peterson's "tile is on the way" went to Emily and
        // Wyeth Jordan, but Jenn — a client herself and a vendor — came first
        // and the email was filed against her own jobs (2026-10-06).
        if ($homeowners = $this->homeowners($addresses, $users, $companyVendorId)) {
            return $homeowners + ['vendor_id' => $this->vendorOnThread($homeowners['projects'], $addresses, $users, $vendors, $companyVendorId)];
        }

        foreach ($addresses as $address) {
            if ($user = $users->get($address)) {
                // A designer can be a client (her own house) and a vendor on
                // other jobs at once (Jenn Peterson): both sets are candidates.
                $clientIds = $user->clients()->withoutGlobalScopes()->pluck('clients.id')->map(fn ($id) => (int) $id);
                $clientProjects = $this->clientProjects($clientIds, $companyVendorId);

                $vendorIds = $user->vendors()->withoutGlobalScopes()
                    ->where('vendors.id', '!=', $companyVendorId)
                    ->pluck('vendors.id')
                    ->map(fn ($id) => (int) $id);
                $vendorProjects = $this->vendorProjects($vendorIds, $companyVendorId);

                $projects = $clientProjects->concat($vendorProjects)
                    ->unique('id')
                    ->sortByDesc('id')
                    ->take(self::MAX_CANDIDATES)
                    ->values();

                if ($projects->isNotEmpty()) {
                    return [
                        'user' => $user,
                        'client_id' => $clientProjects->isNotEmpty() ? (int) $clientProjects->first()->client_id : null,
                        'vendor_id' => $vendorProjects->isNotEmpty() ? $this->vendorOn($vendorProjects, $vendorIds) : null,
                        'projects' => $projects,
                    ];
                }
            }

            if ($vendor = $vendors->get($address)) {
                $projects = $this->vendorProjects(collect([(int) $vendor->id]), $companyVendorId);

                if ($projects->isNotEmpty()) {
                    return ['user' => null, 'client_id' => null, 'vendor_id' => (int) $vendor->id, 'projects' => $projects];
                }
            }
        }

        return null;
    }

    /**
     * Everyone on the email who is only a client — a contact on a client of
     * the company and nobody's vendor — with their open projects, in address
     * order. A designer who is also a client (her own house) doesn't count:
     * on a job email she is working, not the homeowner.
     *
     * @param  list<string>  $addresses
     * @param  Collection<string, User>  $users
     * @return array{user: User, client_id: int, projects: Collection<int, Project>}|null
     */
    protected function homeowners(array $addresses, Collection $users, int $companyVendorId): ?array
    {
        $found = collect();

        foreach ($addresses as $address) {
            $user = $users->get($address);

            if (! $user || $user->vendors()->withoutGlobalScopes()->where('vendors.id', '!=', $companyVendorId)->exists()) {
                continue;
            }

            $clientIds = $user->clients()->withoutGlobalScopes()->pluck('clients.id')->map(fn ($id) => (int) $id);
            $projects = $this->clientProjects($clientIds, $companyVendorId);

            if ($projects->isNotEmpty()) {
                $found->push(['user' => $user, 'client_id' => (int) $projects->first()->client_id, 'projects' => $projects]);
            }
        }

        if ($found->isEmpty()) {
            return null;
        }

        return [
            'user' => $found->first()['user'],
            'client_id' => $found->first()['client_id'],
            'projects' => $found->flatMap(fn (array $homeowner) => $homeowner['projects'])
                ->unique('id')
                ->sortByDesc('id')
                ->take(self::MAX_CANDIDATES)
                ->values(),
        ];
    }

    /**
     * The vendor company of anyone else on the email (a designer, a sub,
     * a supplier), preferring one already on these projects.
     *
     * @param  Collection<int, Project>  $projects
     * @param  list<string>  $addresses
     * @param  Collection<string, User>  $users
     * @param  Collection<string, Vendor>  $vendors
     */
    protected function vendorOnThread(Collection $projects, array $addresses, Collection $users, Collection $vendors, int $companyVendorId): ?int
    {
        $vendorIds = collect($addresses)
            ->flatMap(function (string $address) use ($users, $vendors, $companyVendorId) {
                $ids = $users->get($address)?->vendors()->withoutGlobalScopes()
                    ->where('vendors.id', '!=', $companyVendorId)
                    ->pluck('vendors.id')
                    ->all() ?? [];

                if ($vendor = $vendors->get($address)) {
                    $ids[] = $vendor->id;
                }

                return $ids;
            })
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $vendorIds->isEmpty() ? null : $this->vendorOn($projects, $vendorIds);
    }

    /**
     * @param  Collection<int, int>  $clientIds
     * @return Collection<int, Project>
     */
    protected function clientProjects(Collection $clientIds, int $companyVendorId): Collection
    {
        if ($clientIds->isEmpty()) {
            return collect();
        }

        return $this->open(Project::withoutGlobalScopes()
            ->whereIn('client_id', $clientIds)
            ->where('belongs_to_vendor_id', $companyVendorId));
    }

    /**
     * @param  Collection<int, int>  $vendorIds
     * @return Collection<int, Project>
     */
    protected function vendorProjects(Collection $vendorIds, int $companyVendorId): Collection
    {
        if ($vendorIds->isEmpty()) {
            return collect();
        }

        return $this->open(Project::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $companyVendorId)
            ->whereHas('vendors', fn ($query) => $query->withoutGlobalScopes()->whereIn('vendors.id', $vendorIds)));
    }

    /**
     * @return Collection<int, Project>
     */
    protected function open($query): Collection
    {
        return $query
            ->with(['latestStatus' => fn ($status) => $status->withoutGlobalScopes()])
            ->orderByDesc('id')
            ->get()
            ->reject(fn (Project $project) => in_array((int) $project->latestStatus?->status_code, self::CLOSED_STATUS_CODES, true))
            ->take(self::MAX_CANDIDATES)
            ->values();
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, int>  $vendorIds
     */
    protected function vendorOn(Collection $projects, Collection $vendorIds): ?int
    {
        $onProject = DB::table('project_vendor')
            ->whereIn('project_id', $projects->pluck('id'))
            ->whereIn('vendor_id', $vendorIds)
            ->value('vendor_id');

        return $onProject ? (int) $onProject : ($vendorIds->first() ?: null);
    }
}
