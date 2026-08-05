{{-- Reveal the rows past the first, a few at a time. Sits inside an
     x-data="photoRows(n)" wrapper, just below its grid. --}}
<div class="flex items-center justify-center gap-2 pt-2" x-show="more || opened" x-cloak>
    <flux:button size="xs" variant="ghost" x-show="more" x-on:click="showMore()">
        <span x-text="`Show more (${remaining})`"></span>
    </flux:button>
    <flux:button size="xs" variant="ghost" x-show="opened" x-on:click="showLess()">
        Show less
    </flux:button>
</div>
