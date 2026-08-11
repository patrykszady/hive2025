<?php

namespace App\Services;

use App\Livewire\Leads\PickTimes;
use App\Models\CompanyEmail;
use App\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * What the admins' calendars already have booked, shaped for the consult
 * windows: a lead must not be offered "2-4 PM Wednesday" when Patryk or Greg
 * has a meeting inside it.
 *
 * Busy blocks come from ONE Nylas free/busy call — a grant can read its
 * org-mates' calendars, so the first admin grant queries every admin mailbox.
 * Results are cached briefly; the page renders many windows per day and the
 * calendars don't move minute to minute.
 *
 * Fails OPEN: no grants, an API error, or an unresolvable mailbox mean "no
 * busy data", never "everything blocked" — a lead locked out of picking any
 * time is worse than an occasional clash the office reschedules. That also
 * keeps every test environment (grants are empty there) offline and free.
 */
class AdminCalendarBusy
{
    /** Cache lifetime for one free/busy sweep. */
    private const CACHE_MINUTES = 10;

    /**
     * Breathing room around calendar events when judging windows: travel /
     * reset time, so a consult can't be offered back-to-back with a meeting.
     */
    private const BUFFER_MINUTES = 30;

    /**
     * How far ahead busy data is fetched. Matches the Microsoft Graph cap
     * (62 days); the picker's calendar reaches further, but a lead booking
     * two months out is rare enough to leave un-gated.
     */
    public const HORIZON_DAYS = 62;

    public function __construct(private readonly NylasService $nylas) {}

    /**
     * Is this window on this date blocked by an existing event?
     *
     * "Anytime" promises the WHOLE day — one busy window anywhere breaks that
     * promise, so any clash blocks it. (Whole-day greying on the calendar is
     * allWindowsBusy(), a deliberately different rule: a day with one busy
     * window still has times worth offering.)
     */
    public function windowIsBusy(string $date, string $window): bool
    {
        if ($window === 'Anytime') {
            return collect(PickTimes::WINDOWS)
                ->reject(fn (string $w) => $w === 'Anytime')
                ->contains(fn (string $w) => $this->windowIsBusy($date, $w));
        }

        $times = Lead::parseSlotTimes($window);

        if (! $times) {
            return false;
        }

        [$start, $end] = $times;

        $toMinutes = fn (string $t): int => ((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2);
        $toTime = fn (int $m): string => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);

        foreach ($this->busyIntervalsFor($date) as [$busyStart, $busyEnd]) {
            // Overlap, with breathing room: an event starting the minute a
            // window ends still blocks it — a consult booked at the window's
            // last slot would run straight into that meeting with zero travel
            // time. Same on the other edge.
            $paddedStart = $toTime(max(0, $toMinutes($busyStart) - self::BUFFER_MINUTES));
            $paddedEnd = $toTime(min(23 * 60 + 59, $toMinutes($busyEnd) + self::BUFFER_MINUTES));

            if ($start < $paddedEnd && $paddedStart < $end) {
                return true;
            }
        }

        return false;
    }

    /** Every concrete window that day clashes with an event. */
    public function allWindowsBusy(string $date): bool
    {
        return collect(PickTimes::WINDOWS)
            ->reject(fn (string $w) => $w === 'Anytime')
            ->every(fn (string $w) => $this->windowIsBusy($date, $w));
    }

    /**
     * Dates within the horizon where no window at all is free — for greying
     * whole days out on the calendar.
     *
     * @return array<int, string>
     */
    public function fullyBusyDates(string $fromDate): array
    {
        $cursor = Carbon::parse($fromDate, PickTimes::timezone());
        $end = $cursor->copy()->addDays(self::HORIZON_DAYS);
        $dates = [];

        for (; $cursor->lt($end); $cursor->addDay()) {
            $date = $cursor->format('Y-m-d');

            if ($cursor->isWeekend()) {
                continue;
            }

            if ($this->allWindowsBusy($date)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * Busy intervals for one date as [['HH:MM', 'HH:MM'], ...] in the company
     * timezone, clipped to that day.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function busyIntervalsFor(string $date): array
    {
        return $this->busyByDate()[$date] ?? [];
    }

    /**
     * The whole horizon's busy blocks, grouped by company-timezone date.
     *
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    protected function busyByDate(): array
    {
        $accounts = $this->accounts();

        if ($accounts === []) {
            return [];
        }

        $timezone = PickTimes::timezone();
        $from = Carbon::now($timezone)->startOfDay();
        $to = $from->copy()->addDays(self::HORIZON_DAYS)->endOfDay();

        $cacheKey = 'admin_calendar_busy:'.md5(implode('|', array_column($accounts, 'email')).':'.$from->format('Y-m-d'));

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_MINUTES), function () use ($accounts, $from, $to, $timezone) {
            try {
                $response = $this->nylas->getFreeBusy(
                    $accounts[0]['grant_id'],
                    array_column($accounts, 'email'),
                    $from->timestamp,
                    $to->timestamp,
                );

                if (! ($response['success'] ?? false)) {
                    Log::channel('nylas')->warning('Admin free/busy lookup failed — treating calendars as free', [
                        'status' => $response['status'] ?? null,
                    ]);

                    return [];
                }

                $byDate = [];

                foreach (($response['data']['data'] ?? []) as $mailbox) {
                    // Unresolvable mailboxes come back as object:"error" — skip
                    // them rather than blocking anything.
                    foreach (($mailbox['time_slots'] ?? []) as $slot) {
                        $start = Carbon::createFromTimestamp((int) $slot['start_time'], $timezone);
                        $end = Carbon::createFromTimestamp((int) $slot['end_time'], $timezone);

                        // Clip multi-day blocks to per-day intervals so lookups
                        // stay a simple per-date list.
                        for ($day = $start->copy()->startOfDay(); $day->lte($end); $day->addDay()) {
                            $dayStart = max($start, $day)->format('H:i');
                            $dayEnd = $end->lt($day->copy()->endOfDay())
                                ? $end->format('H:i')
                                : '24:00';

                            if ($dayStart !== $dayEnd) {
                                $byDate[$day->format('Y-m-d')][] = [$dayStart, $dayEnd];
                            }
                        }
                    }
                }

                return $byDate;
            } catch (\Throwable $e) {
                Log::channel('nylas')->warning('Admin free/busy lookup threw — treating calendars as free', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * Admin mailboxes with calendar grants — Patryk and Greg. The first grant
     * makes the query for all of them.
     *
     * @return array<int, array{grant_id: string, email: string}>
     */
    protected function accounts(): array
    {
        return CompanyEmail::withoutGlobalScopes()
            ->where('vendor_id', (int) config('nylas.crew_leads.vendor_id', 1))
            ->whereNotNull('grant_id')
            ->where('grant_id', '!=', '')
            ->whereNotNull('email')
            ->get(['grant_id', 'email'])
            ->map(fn (CompanyEmail $row) => ['grant_id' => (string) $row->grant_id, 'email' => (string) $row->email])
            ->all();
    }
}
