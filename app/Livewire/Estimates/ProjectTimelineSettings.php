<?php

namespace App\Livewire\Estimates;

use App\Models\Vendor;
use App\Services\Estimates\TimelineSettings;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The company's project timeline rules (TimelineSettings): its demo days,
 * which US & Illinois holidays the crew works, the limited weeks, booked
 * weeks and the calendar fade. Each Hive company keeps its own, starting
 * from GS Construction's. Company admins only, behind the timeline's gate (Patryk and Greg for now).
 */
#[Title('Project timeline settings')]
class ProjectTimelineSettings extends Component
{
    use AuthorizesRequests;

    /** @var list<string> ISO weekdays */
    public array $demoDays = [];

    /** @var array<string, string> holiday slug => off|work|hide */
    public array $holidays = [];

    public bool $thanksgivingWeek = true;

    public bool $christmasWeek = true;

    public bool $dayBeforeChristmasEve = true;

    public bool $bridgeDays = true;

    public bool $easterDays = true;

    public bool $orthodoxDays = true;

    public bool $bookedWeeks = true;

    public ?int $bookedDays = null;

    /** @var list<string> ProjectStatus codes */
    public array $bookedStatuses = [];

    public ?int $fadeBeforeDays = null;

    public ?int $fadeAfterDays = null;

    public function mount(): void
    {
        $this->authorize('estimate-project-duration');
        $this->authorize('viewOptions', Vendor::class);
        abort_unless(auth()->user()->vendor, 404);

        $this->load(TimelineSettings::forVendor(auth()->user()->vendor));
    }

    /** Back to GS Construction's rules; nothing is saved until Save. */
    public function useDefaults(): void
    {
        $this->load(new TimelineSettings);
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorize('viewOptions', Vendor::class);
        $this->validate();

        TimelineSettings::fromArray([
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
        ])->saveFor(auth()->user()->vendor);

        Flux::toast(variant: 'success', text: 'Timeline settings saved.');
    }

    protected function load(TimelineSettings $settings): void
    {
        // Strings: the checkbox groups compare their values as strings.
        $this->demoDays = array_map('strval', $settings->demoDays);
        $this->holidays = array_map(
            fn (array $holiday): string => $holiday['default'],
            TimelineSettings::civicHolidays(),
        );
        foreach (array_keys($this->holidays) as $slug) {
            $this->holidays[$slug] = $settings->holidays[$slug] ?? $this->holidays[$slug];
        }
        $this->thanksgivingWeek = $settings->thanksgivingWeek;
        $this->christmasWeek = $settings->christmasWeek;
        $this->dayBeforeChristmasEve = $settings->dayBeforeChristmasEve;
        $this->bridgeDays = $settings->bridgeDays;
        $this->easterDays = $settings->easterDays;
        $this->orthodoxDays = $settings->orthodoxDays;
        $this->bookedWeeks = $settings->bookedWeeks;
        $this->bookedDays = $settings->bookedDays;
        $this->bookedStatuses = array_map('strval', $settings->bookedStatuses);
        $this->fadeBeforeDays = $settings->fadeBeforeDays;
        $this->fadeAfterDays = $settings->fadeAfterDays;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'demoDays' => ['required', 'array', 'min:1'],
            'demoDays.*' => ['integer', Rule::in(array_keys(TimelineSettings::WEEKDAYS))],
            'holidays' => ['array'],
            'holidays.*' => [Rule::in([TimelineSettings::HOLIDAY_OFF, TimelineSettings::HOLIDAY_WORK, TimelineSettings::HOLIDAY_HIDE])],
            'thanksgivingWeek' => ['boolean'],
            'christmasWeek' => ['boolean'],
            'dayBeforeChristmasEve' => ['boolean'],
            'bridgeDays' => ['boolean'],
            'easterDays' => ['boolean'],
            'orthodoxDays' => ['boolean'],
            'bookedWeeks' => ['boolean'],
            'bookedDays' => ['required', 'integer', 'min:1', 'max:'.TimelineSettings::MAX_BOOKED_DAYS],
            'bookedStatuses' => ['array', Rule::requiredIf($this->bookedWeeks)],
            'bookedStatuses.*' => ['integer', Rule::in(array_keys(TimelineSettings::BOOKABLE_STATUSES))],
            'fadeBeforeDays' => ['required', 'integer', 'min:0', 'max:'.TimelineSettings::MAX_FADE_BEFORE_DAYS],
            'fadeAfterDays' => ['required', 'integer', 'min:0', 'max:'.TimelineSettings::MAX_FADE_AFTER_DAYS],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'demoDays.required' => 'Pick at least one day a project can start on.',
            'demoDays.min' => 'Pick at least one day a project can start on.',
            'bookedDays.required' => 'Say how many days a job\'s start holds.',
            'bookedDays.min' => 'A job\'s start holds at least its own day.',
            'bookedDays.max' => 'A job\'s start can hold at most :max days.',
            'bookedStatuses.required' => 'Pick the jobs that hold a week, or turn booked weeks off.',
            'fadeBeforeDays.required' => 'Say how many days fade in before the start (0 for none).',
            'fadeBeforeDays.max' => 'The fade before the start can be at most :max days.',
            'fadeAfterDays.required' => 'Say how many days fade out after the finish (0 for none).',
            'fadeAfterDays.max' => 'The fade after the finish can be at most :max days.',
        ];
    }

    public function render()
    {
        return view('livewire.estimates.project-timeline-settings');
    }
}
