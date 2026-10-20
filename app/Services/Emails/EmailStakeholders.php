<?php

namespace App\Services\Emails;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
