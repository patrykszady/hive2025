{{-- Reveal the rows past the first, a few at a time. Sits inside an
     x-data="photoRows(n)" wrapper, just below its grid. photoRows scrolls
     this row into view after every change, so the reader lands on the last
     row of images (scroll-margin keeps it off the viewport edge). --}}
<div class="mt-3 mb-1 flex items-center justify-center gap-2" data-show-more
    style="scroll-margin-bottom: 1.5rem" x-show="more || opened" x-cloak>
    <flux:button size="xs" variant="ghost" x-show="more" x-on:click="showMore()">
        <span x-text="`Show more (${remaining})`"></span>
    </flux:button>
    <flux:button size="xs" variant="ghost" x-show="opened" x-on:click="showLess()">
        Show less
    </flux:button>
</div>
