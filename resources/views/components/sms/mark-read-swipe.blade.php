@props([
    'threadId' => null,
    'unread' => false,
    'buttonClass' => 'right-2',
])

{{--
    One row of a messages list that can be marked read without opening it
    (Patryk, 2026-11-12: "swipe a message … to mark it as read"). On a touch
    screen an unread row slides sideways under the finger, either way, and
    past 80px lets go as "Mark read"; with a pointer a small button shows on
    hover or keyboard focus. Both call markRead() on the Livewire list around
    it (App\Livewire\Sms\Concerns\MarksThreadsRead).

    The markup is the same for read and unread rows — only data-unread and
    the hover button differ — so Livewire's morph never has to add or remove
    Alpine bindings when a row changes state. A sideways drag must not also
    open the conversation or pull the list down to refresh: the move stops
    there, and the click that follows a drag is swallowed.
--}}
<div
    {{ $attributes->class('group/read relative touch-pan-y overflow-hidden rounded-lg') }}
    data-mark-read-row
    data-unread="{{ $unread && $threadId ? '1' : '0' }}"
    x-data="{
        dx: 0,
        startX: 0,
        startY: 0,
        tracking: false,
        swiping: false,
        justSwiped: false,
        sending: false,
        start(e) {
            if (this.$el.dataset.unread !== '1' || this.sending || e.touches.length !== 1) return;
            this.startX = e.touches[0].clientX;
            this.startY = e.touches[0].clientY;
            this.tracking = true;
            this.swiping = false;
            this.dx = 0;
        },
        move(e) {
            if (! this.tracking) return;
            const dx = e.touches[0].clientX - this.startX;
            const dy = e.touches[0].clientY - this.startY;
            if (! this.swiping) {
                if (Math.abs(dy) > 10 && Math.abs(dy) >= Math.abs(dx)) { this.tracking = false; return; }
                if (Math.abs(dx) < 14) return;
                this.swiping = true;
            }
            e.stopPropagation();
            if (e.cancelable) e.preventDefault();
            this.dx = Math.max(-140, Math.min(140, dx));
        },
        end() {
            if (! this.tracking) return;
            this.tracking = false;
            if (! this.swiping) return;
            this.swiping = false;
            this.justSwiped = true;
            setTimeout(() => { this.justSwiped = false; }, 400);
            if (Math.abs(this.dx) < 80) { this.dx = 0; return; }
            this.markRead();
        },
        cancel() {
            this.tracking = false;
            this.swiping = false;
            this.dx = 0;
        },
        markRead() {
            if (this.sending) return;
            this.sending = true;
            $wire.markRead({{ (int) $threadId }}).finally(() => { this.sending = false; this.dx = 0; });
        },
    }"
    x-on:touchstart.passive="start($event)"
    x-on:touchmove="move($event)"
    x-on:touchend="end()"
    x-on:touchcancel="cancel()"
    x-on:click.capture="if (justSwiped) { $event.preventDefault(); $event.stopPropagation(); }"
>
    <div class="pointer-events-none absolute inset-0 flex items-center justify-between bg-indigo-500 px-4 text-sm font-medium text-white" x-show="dx !== 0" x-cloak aria-hidden="true">
        <span class="flex items-center gap-1.5" x-bind:class="dx > 0 ? '' : 'invisible'">
            <flux:icon name="envelope-open" variant="micro" />
            Mark read
        </span>
        <span class="flex items-center gap-1.5" x-bind:class="dx < 0 ? '' : 'invisible'">
            Mark read
            <flux:icon name="envelope-open" variant="micro" />
        </span>
    </div>

    <div
        class="relative"
        x-bind:class="[swiping ? '' : 'transition-transform duration-150', dx !== 0 ? 'bg-white dark:bg-zinc-900' : '']"
        x-bind:style="dx !== 0 ? 'transform: translateX(' + dx + 'px)' : ''"
    >
        {{ $slot }}
    </div>

    @if ($unread && $threadId)
        <div class="{{ $buttonClass }} absolute top-1/2 hidden -translate-y-1/2 group-hover/read:block group-has-[:focus-visible]/read:block" data-mark-read-button>
            <flux:button type="button" size="xs" icon="envelope-open" wire:click="markRead({{ (int) $threadId }})" tooltip="Mark as read" aria-label="Mark as read" />
        </div>
    @endif
</div>
