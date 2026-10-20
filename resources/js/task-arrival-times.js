// Task modal arrival times (resources/views/livewire/tasks/create.blade.php),
// kept in the browser. form.time_settings is entangled without .live, so
// flipping a day on, picking a start and getting the end filled in all happen
// here instantly, and the times reach TaskCreate with the next request
// (Create, a date pick). Each pick used to be a full modal round trip whose
// response re-keyed both pickers, which froze the modal on hive.contractors.
// The rules mirror TaskCreate: defaultEndTime(), copyTimesToDate(),
// toggleAllArrivalTimes() and the end-time fill in updated().

const MEET_MINUTES = 30;

function shiftTime(time, minutes) {
    const [hours, mins] = String(time ?? '').slice(0, 5).split(':').map((part) => parseInt(part, 10));

    if (Number.isNaN(hours) || Number.isNaN(mins)) {
        return null;
    }

    const total = hours * 60 + mins + minutes;

    // A 23:45 Meet keeps the plain mirror rather than ending tomorrow.
    if (total >= 24 * 60) {
        return null;
    }

    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('taskArrivalTimes', (times) => ({
        times,

        init() {
            this.normalize();
            this.$watch('times', () => this.normalize());
        },

        /** The days on screen — the server renders one row per selected date. */
        dates() {
            return [...this.$root.querySelectorAll('[data-arrival-date]')].map((row) => row.dataset.arrivalDate);
        },

        /**
         * PHP sends empty settings as [] — keyed days need an object, or the
         * day keys would be dropped on the way back to the server.
         */
        normalize() {
            if (!this.times || Array.isArray(this.times) || typeof this.times !== 'object') {
                this.times = {};
            }

            this.dates().forEach((date) => this.ensure(date));
        },

        /**
         * A day's settings for the bindings. Opening another task swaps the
         * whole settings object before the rows for its dates are morphed in,
         * so a row can briefly ask for a day that is not there yet.
         */
        day(date) {
            return (this.times && !Array.isArray(this.times) && this.times[date]) || {};
        },

        ensure(date) {
            if (!this.times || Array.isArray(this.times)) {
                this.times = {};
            }

            if (!this.times[date] || typeof this.times[date] !== 'object') {
                this.times[date] = { use_time: false, start_time: null, end_time: null };
            }

            return this.times[date];
        },

        isMeet() {
            return this.$wire.form?.type === 'Meet';
        },

        /** TaskCreate::defaultEndTime(): a Meet ends 30 minutes in, anything else on its start. */
        defaultEndTime(start) {
            return this.isMeet() ? (shiftTime(start, MEET_MINUTES) ?? start) : start;
        },

        /** TaskCreate::minimumEndTime(): the end picker opens at the default end. */
        minimumEndTime(date) {
            const start = this.times?.[date]?.start_time;

            return start ? this.defaultEndTime(start) : '06:00';
        },

        /** The Arrival Time switch: on while any day has a time, flips every day. */
        get allOn() {
            return this.dates().some((date) => Boolean(this.times?.[date]?.use_time));
        },

        set allOn(enabled) {
            this.toggleAll(Boolean(enabled));
        },

        /** A start pick fills the end when it is missing or no longer after the start. */
        startPicked(date) {
            const day = this.ensure(date);
            const start = String(day.start_time ?? '').trim();
            const end = String(day.end_time ?? '').trim();

            if (start !== '' && (end === '' || end <= start)) {
                day.end_time = this.defaultEndTime(start);
            }
        },

        /** TaskCreate::copyTimesToDate(): a day switched on borrows another day's times. */
        dayToggled(date) {
            const day = this.ensure(date);

            if (!day.use_time || day.start_time) {
                return;
            }

            const source = this.dates()
                .filter((other) => other !== date)
                .map((other) => this.times[other])
                .find((settings) => settings?.start_time);

            if (source) {
                day.start_time = source.start_time;
                day.end_time = source.end_time ?? null;
            }
        },

        /** TaskCreate::toggleAllArrivalTimes(): every selected day on or off at once. */
        toggleAll(enabled) {
            const dates = this.dates();
            const source = enabled
                ? dates.map((date) => this.times?.[date]).find((settings) => settings?.start_time)
                : null;

            dates.forEach((date) => {
                const day = this.ensure(date);

                day.use_time = enabled;

                if (enabled && !day.start_time && source) {
                    day.start_time = source.start_time;
                    day.end_time = source.end_time ?? null;
                }
            });

            this.$nextTick(() => this.$root.scrollIntoView({ behavior: 'smooth', block: 'end' }));
        },
    }));
});
