<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
use App\Models\Project;
use App\Services\Estimates\ProjectDuration;
use App\Services\Estimates\TimelineSettings;
use App\Support\ConstructionHolidays;
use App\Support\FaithHolidays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * "Estimate your project duration": twelve months on one screen, from this
 * month on (the arrows move them a month at a time). A click sets
 * the project start — always a Tuesday–Thursday demo day — or the week the
 * cabinets arrive (suppliers promise a week, not a day), and the start then
 * falls on the demo day the contractor's prep weeks before it. A placed
 * cabinet week stays put: the click switches to the start, whose hover and
 * click move only the start. With a cabinet week and the weeks needed before
 * it, the calendar marks the best time to start in green — the demo days of
 * the week they begin, less holidays we stay away and other jobs' booked
 * days. The project is one blue band from start to finish, either side of
 * the delivery; the days before and after it fade in and out in yellow. Hovering previews the schedule from the day under the
 * mouse (Alpine, in the view). The chosen length marks the finish, and the
 * holidays the project runs across are called out; the homeowner's faiths
 * add theirs (indigo), listed for the months on screen whether or not a project
 * is set. Each holiday is a pill matching its calendar tile: yellow (light
 * indigo for faith) when the homeowner can have us and we work — the minor
 * ones like Pulaski Day start that way — red (indigo for faith) when we stay
 * away, and those weekdays come off the working days.
 *
 * GS Construction's own Scheduled and Active jobs grey out the week each one
 * starts: a click will not start a new project there, a double-click will
 * (on purpose), and the summary then warns two jobs start that week.
 *
 * Every rule here — demo days, which holidays the crew works, limited weeks,
 * booked weeks, the fade — is the user's company's (TimelineSettings, set on
 * /project-timeline/settings); GS Construction's are the defaults.
 *
 * Days before today cannot be picked, nor can a start snap back onto one.
 *
 * The page opens blank — nothing preselected and nothing carried in the URL.
 * Patryk and Greg only for now (the estimate-project-duration gate).
 */
#[Title('Project timeline')]
class ProjectTimeline extends Component
{
    use AuthorizesRequests;

    /** The first of the twelve months on screen, Y-m. */
    #[Locked]
    public string $from;

    #[Locked]
    public ?string $start = null;

    /** The Monday of the cabinet delivery week. */
    #[Locked]
    public ?string $cabinets = null;

    /** A ProjectDuration::PRESETS key. */
    public ?string $length = null;

    public ?int $prepWeeks = null;

    /** @var list<string> FaithHolidays::FAITHS keys */
    public array $faiths = [];

    /** @var list<string> "Y-m-d:slug" keys of the holidays switched away from their default */
    #[Locked]
    public array $holidayChanges = [];

    /** What a click on a day sets: 'start' or 'cabinets'; a first click with neither sets the start. */
    public ?string $clickSets = null;

    public function mount(): void
    {
        $this->authorize('estimate-project-duration');

        $this->from = isset($this->from) && preg_match('/^\d{4}-\d{2}$/', $this->from) ? $this->from : now()->format('Y-m');
    }

    /** $overrideBooked: a double-click starts the project in a booked week anyway. */
    public function pickDate(string $date, bool $overrideBooked = false): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < now()->toDateString()) {
            return;
        }

        if ($this->clickSets !== 'cabinets' && isset($this->bookedDays[$date]) && ! $overrideBooked) {
            return;
        }

        if ($this->clickSets === 'cabinets') {
            $this->cabinets = ProjectDuration::cabinetWeek(CarbonImmutable::parse($date))->toDateString();
            $this->startFromCabinets();
            $this->clickSets = 'start';

            return;
        }

        $this->clickSets = 'start';
        $this->start = ProjectDuration::demoDay(CarbonImmutable::parse($date), $this->settings->demoDays, now()->toImmutable())->toDateString();
    }

    public function updatedClickSets(): void
    {
        if (! in_array($this->clickSets, ['start', 'cabinets'], true)) {
            $this->clickSets = null;
        }
    }

    public function updatedPrepWeeks(): void
    {
        if (! in_array($this->prepWeeks, ProjectDuration::PREP_WEEKS, true)) {
            $this->prepWeeks = null;
        }

        $this->startFromCabinets();
    }

    public function updatedFaiths(): void
    {
        $this->faiths = array_values(array_intersect(array_keys(FaithHolidays::FAITHS), (array) $this->faiths));
    }

    /** Flip a holiday between "the homeowner can have us" and "we stay away". */
    public function toggleHoliday(string $key): void
    {
        if (! self::isHolidayKey($key)) {
            return;
        }

        $this->holidayChanges = in_array($key, $this->holidayChanges, true)
            ? array_values(array_diff($this->holidayChanges, [$key]))
            : [...$this->holidayChanges, $key];
    }

    public function updatedLength(): void
    {
        if ($this->length !== null && ! array_key_exists($this->length, ProjectDuration::PRESETS)) {
            $this->length = null;
        }
    }

    public function previousMonth(): void
    {
        $this->showFrom($this->window['from']->subMonth());
    }

    public function nextMonth(): void
    {
        $this->showFrom($this->window['from']->addMonth());
    }

    public function thisMonth(): void
    {
        $this->showFrom(now()->toImmutable());
    }

    /** Slide the months so the project's finish shows, keeping its start on screen when it fits. */
    public function showProject(): void
    {
        if (! $this->start) {
            return;
        }

        $startMonth = CarbonImmutable::parse($this->start)->startOfMonth();
        $finishMonth = $this->finish?->startOfMonth() ?? $startMonth;

        $this->showFrom($finishMonth->subMonths(11)->max($startMonth));
    }

    /** Start the twelve months at $month's month (and drop the cached window). */
    protected function showFrom(CarbonImmutable $month): void
    {
        $this->from = $month->format('Y-m');
        unset($this->window);
    }

    /**
     * The twelve months on screen: their first and last day, and a label.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string}
     */
    #[Computed]
    public function window(): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m', $this->from)->startOfMonth();
        $to = $from->addMonths(12)->subDay();

        return ['from' => $from, 'to' => $to, 'label' => $from->format('M Y').' – '.$to->format('M Y')];
    }

    public function clearDates(): void
    {
        $this->start = null;
        $this->cabinets = null;
        $this->clickSets = null;
    }

    protected static function isHolidayKey(string $key): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}:[a-z0-9-]+$/', $key);
    }

    /**
     * The civic and faith holidays from $start to $finish, each with its pill
     * state: works (the homeowner can have us) after the user's switches.
     *
     * @return list<array{date: string, name: string, kind: string, faith: ?string, observed: bool, works_by_default: bool, note: ?string, key: string, label: string, weekend: bool, works: bool}>
     */
    protected function holidaysBetween(CarbonImmutable $start, CarbonImmutable $finish): array
    {
        return self::byDate(array_map(
            fn (array $holiday): array => $this->withPillState($holiday),
            [...$this->settings->applyTo(ConstructionHolidays::between($start, $finish)), ...FaithHolidays::between($start, $finish, $this->faiths)],
        ));
    }

    /**
     * @param  list<array{date: string}>  $holidays
     * @return list<array<string, mixed>>
     */
    protected static function byDate(array $holidays): array
    {
        usort($holidays, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $holidays;
    }

    /**
     * @param  array{date: string, name: string, works_by_default: bool}  $holiday
     * @return array<string, mixed>
     */
    protected function withPillState(array $holiday): array
    {
        $key = $holiday['date'].':'.Str::slug($holiday['name']);
        $date = CarbonImmutable::parse($holiday['date']);

        return $holiday + [
            'key' => $key,
            'label' => $date->format('D M j').' · '.$holiday['name'],
            'weekend' => $date->isWeekend(),
            'works' => $holiday['works_by_default'] xor in_array($key, $this->holidayChanges, true),
        ];
    }

    /**
     * The weekdays a holiday keeps us out of the house: Y-m-d strings.
     *
     * @param  list<array{date: string, weekend: bool, works: bool}>  $holidays
     * @return list<string>
     */
    protected static function daysOff(array $holidays): array
    {
        return array_values(array_unique(array_column(
            array_filter($holidays, fn (array $holiday) => ! $holiday['weekend'] && ! $holiday['works']),
            'date',
        )));
    }

    /**
     * The company's limited (thin crew) days from $start to $finish.
     *
     * @return list<string>
     */
    protected function limitedDays(CarbonImmutable $start, CarbonImmutable $finish): array
    {
        return array_keys($this->limitedDayReasons($start, $finish));
    }

    /**
     * @return array<string, string> Y-m-d => why the crew is thin
     */
    protected function limitedDayReasons(CarbonImmutable $start, CarbonImmutable $finish): array
    {
        $settings = $this->settings;

        return ConstructionHolidays::limitedDayReasons($start, $finish, $settings->thanksgivingWeek, $settings->christmasWeek, $settings->bridgeDays, $settings->easterDays, $settings->orthodoxDays, $settings->dayBeforeChristmasEve);
    }

    /**
     * A cabinet week and the weeks needed before it put the start on the
     * first best day — or, when the best days have passed, the next demo day.
     */
    protected function startFromCabinets(): void
    {
        if ($this->cabinets && $this->prepWeeks) {
            $today = now()->toImmutable()->startOfDay();
            $this->start = $this->bestStartDays[0]
                ?? ProjectDuration::demoDay($this->prepFrom()->max($today), $this->settings->demoDays, $today)->toDateString();
        }
    }

    /**
     * Where the weeks needed before the cabinets begin: the demo day of the
     * week $prepWeeks before the delivery week (GS: its Tuesday).
     */
    protected function prepFrom(): ?CarbonImmutable
    {
        return $this->cabinets && $this->prepWeeks
            ? ProjectDuration::startBeforeCabinets(CarbonImmutable::parse($this->cabinets), $this->prepWeeks, $this->settings->demoDays)
            : null;
    }

    /**
     * The best time to start: the company's demo days in the week the needed
     * weeks before the cabinets begin, less the days a holiday keeps us away
     * and the days another job's start holds, and the days already gone.
     * Empty without a cabinet week and the weeks needed.
     *
     * @return list<string> Y-m-d
     */
    #[Computed]
    public function bestStartDays(): array
    {
        if (! $this->cabinets || ! $this->prepWeeks) {
            return [];
        }

        $monday = CarbonImmutable::parse($this->cabinets)->subWeeks($this->prepWeeks);
        $daysOff = array_flip(self::daysOff($this->holidaysBetween($monday, $monday->addDays(6))));
        $best = [];

        for ($offset = 0; $offset < 6; $offset++) {
            $day = $monday->addDays($offset);
            $date = $day->toDateString();

            if ($date >= now()->toDateString() && in_array($day->dayOfWeekIso, $this->settings->demoDays, true) && ! isset($daysOff[$date]) && ! isset($this->bookedDays[$date])) {
                $best[] = $date;
            }
        }

        return $best;
    }

    /** The user's company's timeline rules. */
    #[Computed]
    public function settings(): TimelineSettings
    {
        return TimelineSettings::forVendor(auth()->user()?->vendor);
    }

    /**
     * The days another GS job is starting: each Scheduled or Active project
     * books its start day and the six after it (the company sets the days,
     * the statuses, or turns booking off). A job starts on its earliest
     * estimate start, as projects:activate-scheduled reads it — not on a
     * status date: statuses move back and forth (429 Helen went Active Jun
     * 30, Complete Sep 29, and Active again Oct 21 when it was reopened), and
     * hours start with the estimating visits. Only a job with no estimate
     * date falls back to the first day it went Active. Statuses are the
     * user's company's (ProjectStatusScope), so only GS jobs count.
     *
     * @return array<string, string> Y-m-d => "Booked: Kitchen (917 Marion) starts Mon Nov 2"
     */
    #[Computed]
    public function bookedDays(): array
    {
        $settings = $this->settings;

        if (! $settings->bookedWeeks || $settings->bookedStatuses === []) {
            return [];
        }

        $projects = Project::query()
            ->whereHas('latestStatus', fn ($query) => $query->whereIn('status_code', $settings->bookedStatuses))
            ->with(['estimates', 'statuses' => fn ($query) => $query->where('status_code', 6)])
            ->get();

        $booked = [];

        foreach ($projects as $project) {
            $start = $project->estimates->map(fn (Estimate $estimate) => $estimate->start_date)->filter()->sort()->first()
                ?? $project->statuses->pluck('start_date')->filter()->sort()->first();

            if (! $start) {
                continue;
            }

            $start = CarbonImmutable::parse($start)->startOfDay();
            $label = 'Booked: '.$project->name.($project->short_address ? ' ('.$project->short_address.')' : '').' starts '.$start->format('D M j');

            for ($offset = 0; $offset < $settings->bookedDays; $offset++) {
                $date = $start->addDays($offset)->toDateString();
                $booked[$date] = isset($booked[$date]) ? $booked[$date].' · '.$label : $label;
            }
        }

        ksort($booked);

        return $booked;
    }

    #[Computed]
    public function finish(): ?CarbonImmutable
    {
        return $this->start && $this->length ? ProjectDuration::finish(CarbonImmutable::parse($this->start), $this->length) : null;
    }

    /**
     * The project at a glance, or null before a start or cabinet week is
     * picked. The counts wait for both a start and a length.
     *
     * @return array{start: ?CarbonImmutable, start_booked: ?string, start_timing: ?string, best_passed: bool, best_from: ?CarbonImmutable, best_to: ?CarbonImmutable, finish: ?CarbonImmutable, cabinets: ?CarbonImmutable, cabinets_until: ?CarbonImmutable, cabinets_week: ?string, prep_weeks: ?int, cabinets_before_start: bool, calendar_days: ?int, working_days: ?int, days_off: int, limited_days: int, weeks: ?float, cabinets_after_finish: bool}|null
     */
    #[Computed]
    public function summary(): ?array
    {
        if (! $this->start && ! $this->cabinets) {
            return null;
        }

        $start = $this->start ? CarbonImmutable::parse($this->start) : null;
        $finish = $this->finish;
        $cabinets = $this->cabinets ? CarbonImmutable::parse($this->cabinets) : null;
        $days = $start && $finish ? ProjectDuration::calendarDays($start, $finish) : null;
        $daysOff = $start && $finish ? self::daysOff($this->holidaysBetween($start, $finish)) : [];
        $best = $this->bestStartDays;

        return [
            'start' => $start,
            // A cabinet week can put the start inside another job's booked week.
            'start_booked' => $start ? ($this->bookedDays[$start->toDateString()] ?? null) : null,
            // best, early (more weeks before the cabinets than needed) or late (fewer).
            'start_timing' => $start && $best ? match (true) {
                $start->toDateString() < $best[0] => 'early',
                $start->toDateString() > end($best) => 'late',
                default => 'best',
            } : null,
            // The cabinets come too soon for the weeks needed: the best start is already gone.
            'best_passed' => $cabinets && $this->prepWeeks && $best === [] && $this->prepFrom()->toDateString() < now()->toDateString(),
            'best_from' => $best ? CarbonImmutable::parse($best[0]) : null,
            'best_to' => $best ? CarbonImmutable::parse(end($best)) : null,
            'finish' => $finish,
            'cabinets' => $cabinets,
            'cabinets_until' => $cabinets?->addDays(4),
            'cabinets_week' => $cabinets ? ProjectDuration::weekOfMonth($cabinets) : null,
            'prep_weeks' => $start && $cabinets ? ProjectDuration::weeksBeforeCabinets($start, $cabinets) : null,
            'cabinets_before_start' => $start && $cabinets && $cabinets->lessThanOrEqualTo($start),
            'calendar_days' => $days,
            'working_days' => $days ? ProjectDuration::workingDays($start, $finish, $daysOff) : null,
            'days_off' => count($daysOff),
            'limited_days' => $days ? count(array_filter(
                $this->limitedDays($start, $finish),
                fn (string $date) => ! CarbonImmutable::parse($date)->isWeekend() && ! in_array($date, $daysOff, true),
            )) : 0,
            'weeks' => $days ? round($days / 7, 1) : null,
            'cabinets_after_finish' => $cabinets !== null && $finish !== null && $cabinets->greaterThan($finish),
        ];
    }

    /**
     * The holiday pills, grouped US & Illinois first, then each faith. The
     * civic ones are the project's (none until it has a start and a length);
     * a picked faith lists its holidays for the months on screen, plus any the
     * project runs into beyond it. in_project fades the ones outside it.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    #[Computed]
    public function holidayGroups(): array
    {
        $start = $this->start ? CarbonImmutable::parse($this->start) : null;
        $finish = $this->finish;
        $inProject = fn (array $holiday): bool => $start && $finish && $holiday['date'] >= $start->toDateString() && $holiday['date'] <= $finish->toDateString();

        $civic = $start && $finish ? $this->settings->applyTo(ConstructionHolidays::between($start, $finish)) : [];
        $faith = collect([
            ...FaithHolidays::between($this->window['from'], $this->window['to'], $this->faiths),
            ...($start && $finish ? FaithHolidays::between($start, $finish, $this->faiths) : []),
        ])->unique(fn (array $holiday) => $holiday['date'].$holiday['name'])->all();

        $groups = ['civic' => []] + array_fill_keys(array_keys(FaithHolidays::FAITHS), []);
        foreach (self::byDate([...$civic, ...$faith]) as $holiday) {
            $groups[$holiday['faith'] ?? 'civic'][] = $this->withPillState($holiday) + ['in_project' => $inProject($holiday)];
        }

        return array_filter($groups);
    }

    /**
     * The twelve months on screen, each as weeks of seven cells (null outside
     * the month), Sunday first.
     *
     * @return list<array{key: string, name: string, weeks: list<list<array<string, mixed>|null>>}>
     */
    #[Computed]
    public function months(): array
    {
        $start = $this->start;
        $finish = $this->finish?->toDateString();
        $cabinets = $this->cabinets;
        $cabinetsUntil = $cabinets ? CarbonImmutable::parse($cabinets)->addDays(4)->toDateString() : null;
        $startDay = $start ? CarbonImmutable::parse($start) : null;
        $booked = $this->bookedDays;
        $best = array_flip($this->bestStartDays);
        $finishDay = $this->finish;
        ['from' => $windowFrom, 'to' => $windowTo] = $this->window;
        $limited = $this->limitedDayReasons($windowFrom, $windowTo);
        $settings = $this->settings;
        $today = now()->toDateString();
        $holidays = collect([...$settings->applyTo(ConstructionHolidays::between($windowFrom, $windowTo)), ...FaithHolidays::between($windowFrom, $windowTo, $this->faiths)])
            ->map(fn (array $holiday): array => $this->withPillState($holiday))
            ->groupBy('date');

        $months = [];

        for ($offset = 0; $offset < 12; $offset++) {
            $first = $windowFrom->addMonths($offset);
            $cells = array_fill(0, $first->dayOfWeek, null);

            for ($day = $first; $day->month === $first->month; $day = $day->addDay()) {
                $date = $day->toDateString();
                $holiday = $holidays->get($date);
                $holidayOff = $holiday && $holiday->contains('works', false);
                // A stay-away holiday outranks a working one; US & Illinois outrank faith.
                $shown = $holidayOff ? $holiday->where('works', false) : $holiday;
                $holidayTone = $holiday ? ($shown->contains(fn (array $h) => $h['kind'] !== ConstructionHolidays::FAITH) ? 'holiday' : 'faith').($holidayOff ? '_off' : '_on') : null;

                // One look per day, strongest first; holidays are red tiles, faith ones indigo (solid when we stay away).
                $tone = match (true) {
                    $date < $today => 'past',
                    $date === $start || $date === $finish => 'endpoint',
                    $cabinets && $date >= $cabinets && $date <= $cabinetsUntil => 'cabinets',
                    // Best days are never stay-away holidays or booked, so this only outranks a holiday we work.
                    isset($best[$date]) => 'best',
                    $holidayTone !== null => $holidayTone,
                    isset($booked[$date]) => 'booked',
                    $start && $finish && $date >= $start && $date <= $finish => 'project',
                    $day->isWeekend() => 'weekend',
                    default => 'day',
                };

                $cells[] = [
                    'date' => $date,
                    'day' => $day->day,
                    'today' => $date === $today,
                    'past' => $date < $today,
                    'weekend' => $day->isWeekend(),
                    'tone' => $tone,
                    // Plain days just outside the project fade in before it and out after it.
                    'fade' => in_array($tone, ['day', 'weekend'], true) ? ProjectDuration::fade($day, $startDay, $finishDay, $settings->fadeBeforeDays, $settings->fadeAfterDays) : null,
                    'limited' => isset($limited[$date]),
                    'booked' => $booked[$date] ?? null,
                    'title' => implode(' · ', array_filter([
                        $booked[$date] ?? null,
                        $holiday ? $holiday->pluck('name')->implode(', ').match (true) {
                            $day->isWeekend() => '',
                            $holidayOff => ' — we stay away',
                            default => ' — the homeowner can have us',
                        } : null,
                        isset($limited[$date]) ? 'Limited day (thin crew): '.$limited[$date] : null,
                    ])) ?: null,
                ];
            }

            $months[] = ['key' => $first->format('Y-m'), 'name' => $first->format('F Y'), 'weeks' => array_chunk(array_pad($cells, (int) ceil(count($cells) / 7) * 7, null), 7)];
        }

        return $months;
    }

    public function render()
    {
        return view('livewire.estimates.project-timeline');
    }
}
