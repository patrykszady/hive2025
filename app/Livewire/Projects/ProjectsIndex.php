<?php

namespace App\Livewire\Projects;

use App\Models\Client;
use App\Models\EmailTracking;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Services\ProjectStatusTrends;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use App\Livewire\Concerns\RemembersFilters;

class ProjectsIndex extends Component
{
    use AuthorizesRequests, WithPagination, RemembersFilters;

    public $project_name_search = '';
    public $client_id = null;

    public $client = null;

    /**
     * The status codes the list shows; empty = every status. Still named for
     * the single status it once held, so ?project_status_title=6 links keep
     * working (read through selectedStatusCodes(), which takes either form).
     */
    public $project_status_title = [6];

    protected function queryString(): array
    {
        $params = [
            'project_name_search' => ['except' => ''],
            'client_id' => ['except' => null],
        ];

        if (! $this->view) {
            $params['project_status_title'] = ['except' => []];
        }

        return $params;
    }

    public $view = null;
    public $skipProjectSearchReset = false;
    public $skipClientReset = false;

    public function mount()
    {
        if (request()->has('project_name_search')) {
            $this->project_name_search = (string) request()->query('project_name_search');
        }

        if (request()->has('client_id')) {
            $clientId = request()->query('client_id');
            $this->client_id = $clientId !== '' && $clientId !== null ? (int) $clientId : null;
        }


        $this->skipProjectSearchReset = request()->filled('project_name_search');
        $this->skipClientReset = request()->filled('client_id');
        $hasFilterParams = request()->filled('client_id') || request()->filled('project_name_search');
        $hasStatusParam = request()->has('project_status_title');

        if ($this->client) {
            $this->client_id = $this->client->id;
        }

        // Special case for view mode
        if ($this->view == true) {
            $this->project_status_title = [];
            return;
        }

        // Check URL parameters first
        if ($hasStatusParam) {
            $this->project_status_title = self::normalizeStatusCodes(request()->query('project_status_title'));
        } elseif (auth()->user()->is_browsing_as_client) {
            $this->project_status_title = [];
        } else {
            $hasActiveProjects = ProjectStatus::where('status_code', 6)
                ->whereHas('project')
                ->exists();

            $this->project_status_title = $hasActiveProjects ? [6] : [];
        }

        if ($hasFilterParams && ! $hasStatusParam) {
            $this->project_status_title = [];
        }
    }

    /**
     * Status codes from a URL or the Status select: one code
     * (?project_status_title=6, the old links) or a list, each kept only when
     * it is a selectable status. Derived, not hardcoded: a literal list
     * silently dropped Consult (9) when it was added.
     *
     * @return list<int>
     */
    public static function normalizeStatusCodes(mixed $value): array
    {
        $validCodes = array_column(ProjectStatus::selectableStatuses(), 'code');

        return collect(is_array($value) ? $value : [$value])
            ->filter(fn ($code) => is_numeric($code))
            ->map(fn ($code) => (int) $code)
            ->filter(fn (int $code) => in_array($code, $validCodes, true))
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    public function selectedStatusCodes(): array
    {
        return self::normalizeStatusCodes($this->project_status_title);
    }


    public function updating($field)
    {
        $this->resetPage();
    }

    /**
     * A filter change swaps the list in place. Livewire 4 runs a View
     * Transition on every update of a component holding a wire:transition
     * element (the lazy cards below carry one for their first fade-in), and
     * that blinked the whole Projects card out and faded it back on each
     * status change (2026-10-15).
     */
    public function updated($field)
    {
        $this->skipTransition();

        if (str_starts_with((string) $field, 'project_status_title')) {
            $this->project_status_title = $this->selectedStatusCodes();
        }
        
        // Reset filters logic
        if ($field === 'client_id') {
            if ($this->client_id === '') {
                $this->client_id = null;
            }

            if ($this->skipClientReset) {
                $this->skipClientReset = false;
                return;
            }

            return;
        }

        if ($field === 'project_name_search') {
            if ($this->skipProjectSearchReset) {
                $this->skipProjectSearchReset = false;
                return;
            }
        }
    }


    #[Computed]
    public function clients()
    {
        if (auth()->user()->is_browsing_as_client) {
            return auth()->user()->clients()->get();
        }

        return Client::cachedDropdownList();
    }

    /**
     * Memoized so the blade's "does this client have any email tracking to
     * show" check runs its `exists()` query once per render.
     */
    #[Computed]
    public function hasEmailTrackingForClient(): bool
    {
        return ! $this->client_id
            || EmailTracking::clientFacing()->forClientAndItsLeads($this->client_id)->exists();
    }

    /**
     * Statuses that get a card, in card order: work underway first (Active,
     * Service Call, Prep, Scheduled), then the sales pipeline (Estimate,
     * Response, Consult, Invited). Complete, Cancelled and VIEW ONLY are
     * archive, not something to act on.
     */
    public const STATUS_CARD_CODES = [6, 8, 4, 5, 2, 3, 9, 1];

    /** Statuses where more projects than before is worse news. */
    public const STATUS_CARDS_GROWTH_IS_WORSE = [8];

    /**
     * Each card's sparkline, selected border and count badge in its status's
     * badge colour; the count is a fainter tint than the status badge (Flux's
     * is 400/25 behind 700 text). Written out whole so Tailwind compiles every
     * class (it scans this file).
     */
    public const STATUS_CARD_STYLES = [
        'zinc' => [
            'line' => 'text-zinc-400 dark:text-zinc-400',
            'area' => 'text-zinc-100 dark:text-zinc-400/20',
            'selected' => 'aria-pressed:border-zinc-400 dark:aria-pressed:border-zinc-400/70',
            'count' => 'bg-zinc-400/10 text-zinc-700/80 dark:bg-zinc-400/15 dark:text-zinc-200/80',
        ],
        'purple' => [
            'line' => 'text-purple-400 dark:text-purple-400',
            'area' => 'text-purple-100 dark:text-purple-400/20',
            'selected' => 'aria-pressed:border-purple-400 dark:aria-pressed:border-purple-400/70',
            'count' => 'bg-purple-400/10 text-purple-700/80 dark:bg-purple-400/15 dark:text-purple-200/80',
        ],
        'blue' => [
            'line' => 'text-blue-400 dark:text-blue-400',
            'area' => 'text-blue-100 dark:text-blue-400/20',
            'selected' => 'aria-pressed:border-blue-400 dark:aria-pressed:border-blue-400/70',
            'count' => 'bg-blue-400/10 text-blue-700/80 dark:bg-blue-400/15 dark:text-blue-200/80',
        ],
        'yellow' => [
            'line' => 'text-yellow-400 dark:text-yellow-400',
            'area' => 'text-yellow-100 dark:text-yellow-400/20',
            'selected' => 'aria-pressed:border-yellow-400 dark:aria-pressed:border-yellow-400/70',
            'count' => 'bg-yellow-400/10 text-yellow-700/80 dark:bg-yellow-400/15 dark:text-yellow-200/80',
        ],
        'amber' => [
            'line' => 'text-amber-400 dark:text-amber-400',
            'area' => 'text-amber-100 dark:text-amber-400/20',
            'selected' => 'aria-pressed:border-amber-400 dark:aria-pressed:border-amber-400/70',
            'count' => 'bg-amber-400/10 text-amber-700/80 dark:bg-amber-400/15 dark:text-amber-200/80',
        ],
        'lime' => [
            'line' => 'text-lime-400 dark:text-lime-400',
            'area' => 'text-lime-100 dark:text-lime-400/20',
            'selected' => 'aria-pressed:border-lime-400 dark:aria-pressed:border-lime-400/70',
            'count' => 'bg-lime-400/10 text-lime-700/80 dark:bg-lime-400/15 dark:text-lime-200/80',
        ],
        'green' => [
            'line' => 'text-green-400 dark:text-green-400',
            'area' => 'text-green-100 dark:text-green-400/20',
            'selected' => 'aria-pressed:border-green-400 dark:aria-pressed:border-green-400/70',
            'count' => 'bg-green-400/10 text-green-700/80 dark:bg-green-400/15 dark:text-green-200/80',
        ],
        'orange' => [
            'line' => 'text-orange-400 dark:text-orange-400',
            'area' => 'text-orange-100 dark:text-orange-400/20',
            'selected' => 'aria-pressed:border-orange-400 dark:aria-pressed:border-orange-400/70',
            'count' => 'bg-orange-400/10 text-orange-700/80 dark:bg-orange-400/15 dark:text-orange-200/80',
        ],
    ];

    /**
     * The status cards under the filters (Flux dashboard stats), in
     * STATUS_CARD_CODES order: each open status with projects now or 30 days
     * ago, its count, the change since then (green when better, red when
     * worse) and a 12-week sparkline. Company-wide, from status history.
     *
     * @return list<array{code: int, label: string, color: string, count: int, before: int, delta: int, better: bool|null, points: list<int>, selected: bool, style: array{line: string, area: string, selected: string, count: string}}>
     */
    #[Computed]
    public function statusCards(): array
    {
        $vendorId = (int) (auth()->user()->vendor?->id ?? 0);

        if ($this->view || auth()->user()->is_browsing_as_client || ! $vendorId) {
            return [];
        }

        $trends = app(ProjectStatusTrends::class)->forVendor($vendorId, now(vendor_timezone()));
        $selectedCodes = $this->selectedStatusCodes();
        $quiet = ['now' => 0, 'before' => 0, 'points' => array_fill(0, ProjectStatusTrends::WEEKS + 1, 0)];

        $statuses = collect(ProjectStatus::selectableStatuses())->keyBy('code');

        return collect(self::STATUS_CARD_CODES)
            ->map(fn (int $code): array => $statuses[$code])
            ->map(function (array $status) use ($trends, $selectedCodes, $quiet): array {
                $trend = $trends[$status['code']] ?? $quiet;
                $delta = $trend['now'] - $trend['before'];

                return [
                    ...$status,
                    'count' => $trend['now'],
                    'before' => $trend['before'],
                    'delta' => $delta,
                    'better' => $delta === 0 ? null : ($delta > 0) !== in_array($status['code'], self::STATUS_CARDS_GROWTH_IS_WORSE, true),
                    'points' => $trend['points'],
                    'selected' => in_array($status['code'], $selectedCodes, true),
                    'style' => self::STATUS_CARD_STYLES[$status['color']] ?? self::STATUS_CARD_STYLES['zinc'],
                ];
            })
            ->filter(fn (array $card): bool => $card['count'] > 0 || $card['before'] > 0)
            ->values()
            ->all();
    }

    /**
     * The filters this page keeps between visits (see RemembersFilters).
     * None when the client page embeds this list (view set).
     *
     * @return list<string>
     */
    protected function rememberedFilters(): array
    {
        return $this->view ? [] : ['project_name_search', 'client_id', 'project_status_title'];
    }

    #[Title('Projects')]
    public function render()
    {
        $this->authorize('viewAny', Project::class);
        return view('livewire.projects.index', [
            'clients' => $this->clients,
        ]);
    }
}