<?php

namespace App\Livewire\Leads;

use App\Models\Lead;
use App\Models\Vendor;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Public (signed-URL) page where a lead picks new consultation times — linked
 * from the consult email when every preferred slot has passed or none were
 * given. Mirrors the vendor-availability pattern: no auth, the signed route is
 * the credential.
 */
class PickTimes extends Component
{
    #[Locked]
    public int $leadId;

    /**
     * Chosen times. Named $times, NOT $slots — Livewire 4 reserves a `slots`
     * property for component slot forwarding and dehydration crashes on it.
     *
     * @var array<int, array{date: string, time: string}>
     */
    #[Locked]
    public array $times = [];

    public string $date = '';

    public bool $submitted = false;

    /** Same windows the gs.construction lead form offers. */
    public const WINDOWS = ['Anytime', '7-9 AM', '9-11 AM', '11-1 PM', '1-3 PM'];

    public const MAX_SLOTS = 6;

    /** Mirrors the website's ask: at least 3 times across 2 different days. */
    public const MIN_DAYS = 2;

    public const MIN_TIMES = 3;

    /** First contact: consultations are scheduled at least this far ahead. */
    public const MIN_LEAD_HOURS = 72;

    /**
     * Rescheduling: no notice rule at all. Someone moving a consult they
     * already have may take any window that hasn't started yet — including
     * later today. Three days out is what we ask of a first contact, not of a
     * homeowner shuffling an appointment on the morning of.
     *
     * "Hasn't started yet" still holds: the window-start check below measures
     * against this moment, so a 1-3 PM slot stops being offered at 1 PM.
     */
    public const MIN_LEAD_HOURS_RESCHEDULE = 0;

    /**
     * How much a pick is worth toward MIN_TIMES: a whole free day ("Anytime")
     * gives us far more room than one window, so it counts double.
     */
    public static function slotWeight(string $window): int
    {
        return $window === 'Anytime' ? 2 : 1;
    }

    /**
     * The bookable day as ['08:00', '16:00'] — what "Anytime" actually means.
     * Derived from WINDOWS (earliest start, latest end) rather than written
     * out again, so adding or moving a window can't leave the two disagreeing.
     */
    public static function dayBounds(): array
    {
        $ranges = collect(self::WINDOWS)
            ->reject(fn (string $window) => $window === 'Anytime')
            ->map(fn (string $window) => Lead::parseSlotTimes($window))
            ->filter()
            ->values();

        return [$ranges->min(fn ($r) => $r[0]), $ranges->max(fn ($r) => $r[1])];
    }

    /** Send stays disabled until the minimums are met (button + server). */
    public function getCanSubmitProperty(): bool
    {
        $weight = collect($this->times)->sum(fn ($t) => static::slotWeight($t['time']));

        return $weight >= self::MIN_TIMES
            && collect($this->times)->pluck('date')->unique()->count() >= self::MIN_DAYS;
    }

    public function mount(int $lead): void
    {
        // Guests have no vendor scope — resolve explicitly; the signature check
        // in the route middleware is what gates access.
        $this->leadId = Lead::withoutGlobalScopes()->findOrFail($lead)->id;
        $this->date = static::firstBookableDate($this->lead);
    }

    /**
     * The earliest moment a consult may start — in the COMPANY timezone
     * (Central), never the visitor's. This is a public guest page:
     * browser_timezone() has no session here and fell back to UTC, which let
     * the calendar offer a day whose windows then failed validation.
     */
    public static function timezone(): string
    {
        return config('sms.business_hours.timezone', 'America/Chicago');
    }

    /**
     * Rescheduling covers both ways a homeowner arrives here with a meeting
     * already in hand: they came back through the picker before, or there is
     * a consult on the books (the "Need a different time?" link in the
     * calendar invite). Only a genuine first contact waits the full notice.
     */
    public static function isRescheduling(?Lead $lead = null): bool
    {
        return (bool) ($lead?->hasRescheduled() || $lead?->hasBookedConsult());
    }

    public static function minLeadHours(?Lead $lead = null): int
    {
        return static::isRescheduling($lead)
            ? self::MIN_LEAD_HOURS_RESCHEDULE
            : self::MIN_LEAD_HOURS;
    }

    /**
     * Why a pick was refused. With a notice rule it's about notice; without
     * one the only thing left to say is that the slot has already begun —
     * "we need at least 0 hours notice" is not an explanation.
     */
    protected function noticeError(string $noun = 'time'): string
    {
        $hours = static::minLeadHours($this->lead);

        return $hours > 0
            ? "Please pick a later {$noun} — we need at least {$hours} hours notice."
            : "That {$noun} has already passed — please pick a later one.";
    }

    public static function earliestStart(?Lead $lead = null): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::now(static::timezone())
            ->addHours(static::minLeadHours($lead));
    }

    /** Consults are weekdays only; Saturday/Sunday are never offered. */
    public static function isWeekend(string $date): bool
    {
        return \Illuminate\Support\Carbon::parse($date, static::timezone())->isWeekend();
    }

    /**
     * First calendar day with at least one selectable window. When the 72h
     * mark lands after the day's last window start (4 PM), that day would
     * offer nothing but rejections — roll the calendar to the next day.
     */
    public static function firstBookableDate(?Lead $lead = null): string
    {
        $earliest = static::earliestStart($lead);
        $day = $earliest->copy();

        // Walk forward past weekends and past any day whose windows all start
        // before the notice cutoff, so the calendar never opens on a date that
        // would only produce errors. 14 days is a safety stop.
        for ($i = 0; $i < 14; $i++) {
            if (! static::isWeekend($day->format('Y-m-d')) && static::hasWindowOnOrAfter($day->format('Y-m-d'), $earliest)) {
                return $day->format('Y-m-d');
            }

            $day->addDay();
        }

        return $day->format('Y-m-d');
    }

    /** Does $date offer any window starting at or after $earliest? */
    protected static function hasWindowOnOrAfter(string $date, \Illuminate\Support\Carbon $earliest): bool
    {
        return collect(self::WINDOWS)
            ->reject(fn (string $window) => $window === 'Anytime')
            ->contains(function (string $window) use ($date, $earliest) {
                $times = Lead::parseSlotTimes($window);

                return $times && \Illuminate\Support\Carbon::parse(
                    $date.' '.$times[0],
                    static::timezone(),
                )->gte($earliest);
            });
    }

    /**
     * Windows on the SELECTED day the admins already have something for —
     * the blade disables these buttons; toggleWindow refuses them.
     *
     * @return array<int, string>
     */
    public function getBusyWindowsProperty(): array
    {
        if ($this->date === '' || static::isWeekend($this->date)) {
            return [];
        }

        $busy = app(\App\Services\AdminCalendarBusy::class);

        return collect(self::WINDOWS)
            ->filter(fn (string $window) => $busy->windowIsBusy($this->date, $window))
            ->values()
            ->all();
    }

    /**
     * Weekend dates in the months the calendar can show, for its `unavailable`
     * prop — the picker refuses them server-side too.
     *
     * @return array<int, string>
     */
    public static function unavailableDates(?Lead $lead = null): array
    {
        $cursor = \Illuminate\Support\Carbon::parse(static::firstBookableDate($lead), static::timezone())->startOfMonth();
        $end = $cursor->copy()->addMonths(6);
        $dates = [];

        for (; $cursor->lt($end); $cursor->addDay()) {
            if ($cursor->isWeekend()) {
                $dates[] = $cursor->format('Y-m-d');
            }
        }

        // Days where every window clashes with the admins' calendars offer
        // nothing either — grey them like weekends. (Only within the free/busy
        // horizon; further-out days stay open and are re-checked on toggle.)
        $dates = array_merge(
            $dates,
            app(\App\Services\AdminCalendarBusy::class)->fullyBusyDates(static::firstBookableDate($lead)),
        );

        return array_values(array_unique($dates));
    }

    public function getLeadProperty(): Lead
    {
        return Lead::withoutGlobalScopes()->findOrFail($this->leadId);
    }

    public function getVendorProperty(): ?Vendor
    {
        return Vendor::withoutGlobalScopes()->find($this->lead->belongs_to_vendor_id);
    }

    /**
     * Clicking a window on the selected day toggles that (day, window) pair —
     * same interaction as the website's widget.
     */
    public function toggleWindow(string $window): void
    {
        $this->resetErrorBag();

        $this->validate([
            'date' => ['required', 'date', 'after_or_equal:'.static::firstBookableDate($this->lead)],
        ], [
            'date.after_or_equal' => $this->noticeError('date'),
        ]);

        if (! in_array($window, self::WINDOWS, true)) {
            return;
        }

        if (static::isWeekend($this->date)) {
            $this->addError('times', 'Consultations are scheduled Monday through Friday — please pick a weekday.');

            return;
        }

        // The slot must START at least the required notice from now. "Anytime"
        // means "any window you offer that day", so it qualifies whenever the
        // day has at least one qualifying window — measuring it from midnight
        // would offer a button the picker then refuses.
        $earliest = static::earliestStart($this->lead);

        if ($window === 'Anytime') {
            if (! static::hasWindowOnOrAfter($this->date, $earliest)) {
                $this->addError('times', $this->noticeError('date'));

                return;
            }

            // "Anytime" promises the whole day — one existing booking that day
            // breaks the promise, so it's only offered on completely free days.
            if (app(\App\Services\AdminCalendarBusy::class)->windowIsBusy($this->date, 'Anytime')) {
                $this->addError('times', "Anytime isn't available that day — please pick a specific time.");

                return;
            }
        } else {
            $times = Lead::parseSlotTimes($window);
            $start = \Illuminate\Support\Carbon::parse(
                $this->date.' '.($times[0] ?? '00:00'),
                static::timezone(),
            );

            if ($start->lt($earliest)) {
                $this->addError('times', $this->noticeError());

                return;
            }

            if (app(\App\Services\AdminCalendarBusy::class)->windowIsBusy($this->date, $window)) {
                $this->addError('times', 'That time is already booked — please pick another.');

                return;
            }
        }

        $slot = ['date' => $this->date, 'time' => $window];

        $existing = array_search($slot, $this->times, true);

        if ($existing !== false) {
            unset($this->times[$existing]);
            $this->times = array_values($this->times);

            return;
        }

        // Same rule as the vendor availability flow: "Anytime" means the whole
        // day works, so it supersedes that day's specific windows — and picking
        // a specific window replaces the day's Anytime.
        $this->times = array_values(array_filter(
            $this->times,
            fn ($t) => $t['date'] !== $this->date
                || ($window === 'Anytime' ? false : $t['time'] !== 'Anytime'),
        ));

        if (count($this->times) >= self::MAX_SLOTS) {
            $this->addError('times', 'That\'s plenty — you can share up to '.self::MAX_SLOTS.' times.');

            return;
        }

        $this->times[] = $slot;
        // Chronological, not alphabetical — "8-10 AM" belongs before "4-6 PM".
        // Anytime (unparseable) sorts first within its day.
        usort($this->times, fn ($a, $b) => [
            $a['date'], Lead::parseSlotTimes($a['time'])[0] ?? '00:00',
        ] <=> [
            $b['date'], Lead::parseSlotTimes($b['time'])[0] ?? '00:00',
        ]);
    }

    public function removeSlot(int $index): void
    {
        unset($this->times[$index]);
        $this->times = array_values($this->times);
    }

    public function submit(): void
    {
        // The calendars may have filled up between picking and submitting —
        // an event booked five minutes ago must not slip through. Drop any
        // slot that went busy and ask for a replacement instead of storing it.
        $busy = app(\App\Services\AdminCalendarBusy::class);
        $stale = collect($this->times)
            ->filter(fn (array $slot) => $busy->windowIsBusy($slot['date'], $slot['time']))
            ->values();

        if ($stale->isNotEmpty()) {
            $this->times = collect($this->times)
                ->reject(fn (array $slot) => $busy->windowIsBusy($slot['date'], $slot['time']))
                ->values()
                ->all();

            $this->addError('times', 'Sorry — '.$stale
                ->map(fn (array $slot) => \Illuminate\Support\Carbon::parse($slot['date'])->format('D, M j').' · '.$slot['time'])
                ->implode(', ').' just became unavailable. Please pick a replacement.');

            return;
        }

        if (! $this->canSubmit) {
            $this->addError('times', 'Please select at least '.self::MIN_TIMES.' times across '.self::MIN_DAYS.' different days.');

            return;
        }

        $lead = $this->lead;
        $data = $lead->lead_data;
        $data['availability'] = $this->times;
        $data['availability_updated_at'] = now()->toDateTimeString();
        $lead->lead_data = $data;
        $lead->save();

        // The lead answered our ask, so the ball is back with us: drop out of
        // "Replied" (which means "waiting on them") so it shows as needing a
        // reply again — and the Message tab / Remove button come back with it.
        if ($lead->last_status?->title === 'Replied') {
            $lead->setStatus('New');
        }

        $this->submitted = true;
    }

    public function render()
    {
        return view('livewire.leads.pick-times')
            ->layout('components.layouts.guest', [
                'title' => 'Choose consultation times',
            ]);
    }
}
