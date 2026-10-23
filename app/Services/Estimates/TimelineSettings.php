<?php

namespace App\Services\Estimates;

use App\Models\Vendor;
use App\Support\ConstructionHolidays;
use Illuminate\Support\Str;

/**
 * A company's rules for the project timeline, kept in vendors.options under
 * project_timeline (the phone system's pattern). Every company starts from
 * GS Construction's rules — the defaults here — and changes them on
 * /project-timeline/settings:
 *
 * - demo days: the weekdays a project can start (GS: Tuesday–Thursday, never
 *   a Monday or a Friday); a click on another day moves to the nearest one.
 * - holidays: per US & Illinois holiday, whether the crew stays away, works
 *   (yellow, like Pulaski Day) or the calendar leaves it out. Only the ones
 *   changed from their default are stored.
 * - limited days (thin crew): Thanksgiving week, Christmas Eve (GS: the
 *   day before it, Dec 23) to New Year's Day, a lone weekday bridged to the weekend (Friday Jan 2, 2026),
 *   Easter (Holy Thursday, Good Friday, Easter Monday) and the Ukrainian
 *   crew's Orthodox holidays.
 * - booked weeks: the days a job's start holds, and which statuses hold one.
 * - the fade before a project's start and after its finish.
 */
final class TimelineSettings
{
    public const OPTIONS_KEY = 'project_timeline';

    /** ISO weekdays a company can demo on. */
    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

    /** The statuses that can hold a booked week, by ProjectStatus code. */
    public const BOOKABLE_STATUSES = [4 => 'Prep', 5 => 'Scheduled', 6 => 'Active'];

    public const HOLIDAY_OFF = 'off';

    public const HOLIDAY_WORK = 'work';

    public const HOLIDAY_HIDE = 'hide';

    public const MAX_BOOKED_DAYS = 21;

    public const MAX_FADE_BEFORE_DAYS = 30;

    public const MAX_FADE_AFTER_DAYS = 60;

    /** @var array<string, array{name: string, default: string, note: ?string}>|null */
    private static ?array $civicHolidays = null;

    /**
     * @param  list<int>  $demoDays  ISO weekdays
     * @param  array<string, string>  $holidays  holiday slug => off|work|hide, only those changed from their default
     * @param  list<int>  $bookedStatuses  ProjectStatus codes
     */
    public function __construct(
        public readonly array $demoDays = [2, 3, 4],
        public readonly array $holidays = [],
        public readonly bool $thanksgivingWeek = true,
        public readonly bool $christmasWeek = true,
        public readonly bool $dayBeforeChristmasEve = true,
        public readonly bool $bridgeDays = true,
        public readonly bool $easterDays = true,
        public readonly bool $orthodoxDays = true,
        public readonly bool $bookedWeeks = true,
        public readonly int $bookedDays = 7,
        public readonly array $bookedStatuses = [5, 6],
        public readonly int $fadeBeforeDays = 8,
        public readonly int $fadeAfterDays = 16,
    ) {}

    public static function forVendor(?Vendor $vendor): self
    {
        $saved = json_decode(json_encode(data_get($vendor?->options, self::OPTIONS_KEY) ?? []), true);

        return self::fromArray(is_array($saved) ? $saved : []);
    }

    /**
     * Settings from their stored (snake_case) form, anything missing or out of
     * range falling back to the default.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $defaults = new self;
        $civic = self::civicHolidays();

        $holidays = [];
        foreach ((array) ($values['holidays'] ?? []) as $slug => $choice) {
            if (isset($civic[$slug]) && in_array($choice, [self::HOLIDAY_OFF, self::HOLIDAY_WORK, self::HOLIDAY_HIDE], true) && $choice !== $civic[$slug]['default']) {
                $holidays[$slug] = $choice;
            }
        }

        return new self(
            demoDays: self::codesWithin($values['demo_days'] ?? $defaults->demoDays, array_keys(self::WEEKDAYS)) ?: $defaults->demoDays,
            holidays: $holidays,
            thanksgivingWeek: (bool) ($values['thanksgiving_week'] ?? $defaults->thanksgivingWeek),
            christmasWeek: (bool) ($values['christmas_week'] ?? $defaults->christmasWeek),
            dayBeforeChristmasEve: (bool) ($values['day_before_christmas_eve'] ?? $defaults->dayBeforeChristmasEve),
            bridgeDays: (bool) ($values['bridge_days'] ?? $defaults->bridgeDays),
            easterDays: (bool) ($values['easter_days'] ?? $defaults->easterDays),
            orthodoxDays: (bool) ($values['orthodox_days'] ?? $defaults->orthodoxDays),
            bookedWeeks: (bool) ($values['booked_weeks'] ?? $defaults->bookedWeeks),
            bookedDays: self::clamp($values['booked_days'] ?? $defaults->bookedDays, 1, self::MAX_BOOKED_DAYS),
            bookedStatuses: self::codesWithin($values['booked_statuses'] ?? $defaults->bookedStatuses, array_keys(self::BOOKABLE_STATUSES)),
            fadeBeforeDays: self::clamp($values['fade_before_days'] ?? $defaults->fadeBeforeDays, 0, self::MAX_FADE_BEFORE_DAYS),
            fadeAfterDays: self::clamp($values['fade_after_days'] ?? $defaults->fadeAfterDays, 0, self::MAX_FADE_AFTER_DAYS),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'demo_days' => $this->demoDays,
            'holidays' => $this->holidays,
            'thanksgiving_week' => $this->thanksgivingWeek,
            'christmas_week' => $this->christmasWeek,
            'day_before_christmas_eve' => $this->dayBeforeChristmasEve,
            'bridge_days' => $this->bridgeDays,
            'easter_days' => $this->easterDays,
            'orthodox_days' => $this->orthodoxDays,
            'booked_weeks' => $this->bookedWeeks,
            'booked_days' => $this->bookedDays,
            'booked_statuses' => $this->bookedStatuses,
            'fade_before_days' => $this->fadeBeforeDays,
            'fade_after_days' => $this->fadeAfterDays,
        ];
    }

    public function saveFor(Vendor $vendor): void
    {
        $options = (array) ($vendor->options ?? []);
        $options[self::OPTIONS_KEY] = $this->toArray();
        $vendor->options = $options;
        $vendor->save();
    }

    /**
     * The US & Illinois holidays a company sets, in calendar order: slug =>
     * name, default choice and note. Read from an even year so Illinois'
     * Election Day is among them.
     *
     * @return array<string, array{name: string, default: string, note: ?string}>
     */
    public static function civicHolidays(): array
    {
        if (self::$civicHolidays !== null) {
            return self::$civicHolidays;
        }

        $holidays = [];
        foreach (ConstructionHolidays::forYear(2026) as $holiday) {
            if (! $holiday['observed']) {
                $holidays[self::slug($holiday['name'])] = [
                    'name' => $holiday['name'],
                    'default' => $holiday['works_by_default'] ? self::HOLIDAY_WORK : self::HOLIDAY_OFF,
                    'note' => $holiday['note'],
                ];
            }
        }

        return self::$civicHolidays = $holidays;
    }

    /** off, work or hide for a civic holiday; an observed day follows its holiday. */
    public function holidayChoice(string $name): string
    {
        $slug = self::slug($name);

        return $this->holidays[$slug] ?? self::civicHolidays()[$slug]['default'] ?? self::HOLIDAY_OFF;
    }

    /**
     * Civic holidays as this company keeps them: hidden ones dropped, the rest
     * worked or not by its choice.
     *
     * @param  list<array{name: string, works_by_default: bool}>  $holidays
     * @return list<array<string, mixed>>
     */
    public function applyTo(array $holidays): array
    {
        $kept = [];

        foreach ($holidays as $holiday) {
            $choice = $this->holidayChoice($holiday['name']);

            if ($choice !== self::HOLIDAY_HIDE) {
                $kept[] = ['works_by_default' => $choice === self::HOLIDAY_WORK] + $holiday;
            }
        }

        return $kept;
    }

    public static function slug(string $name): string
    {
        return Str::slug(Str::before($name, ' (observed)'));
    }

    /**
     * @param  array<int, int|string>|mixed  $values
     * @param  list<int>  $allowed
     * @return list<int>
     */
    private static function codesWithin(mixed $values, array $allowed): array
    {
        $codes = array_map('intval', array_filter((array) $values, 'is_numeric'));
        $codes = array_values(array_unique(array_intersect($codes, $allowed)));
        sort($codes);

        return $codes;
    }

    private static function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
