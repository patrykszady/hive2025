<div x-data class="mx-auto max-w-3xl min-h-[16rem] w-full space-y-4">
    <div x-disclosure class="rounded-lg bg-white shadow">
        <button
            x-disclosure:button
            class="flex w-full items-center justify-between px-6 py-4 text-xl font-bold"
            >
            <span x-show="$disclosure.isOpen" x-cloak aria-hidden="true" class="ml-4">&minus;</span>
            <span x-show="! $disclosure.isOpen" aria-hidden="true" class="ml-4">&plus;</span>
            <span>Question #1</span>
        </button>

        <div x-disclosure:panel x-collapse>
            <div class="px-6 pb-4">Lorem ipsum dolor sit amet consectetur adipisicing elit. In magnam quod natus deleniti architecto eaque consequuntur ex, illo neque iste repellendus modi, quasi ipsa commodi saepe? Provident ipsa nulla earum.</div>
        </div>
    </div>

    {{-- <div x-disclosure class="rounded-lg bg-white shadow">
        <button
            x-disclosure:button
            class="flex w-full items-center justify-between px-6 py-4 text-xl font-bold"
        >
            <span>Question #2</span>

            <span x-show="$disclosure.isOpen" x-cloak aria-hidden="true" class="ml-4">&minus;</span>
            <span x-show="! $disclosure.isOpen" aria-hidden="true" class="ml-4">&plus;</span>
        </button>

        <div x-disclosure:panel x-collapse>
            <div class="px-6 pb-4">Lorem ipsum dolor sit amet consectetur adipisicing elit. In magnam quod natus deleniti architecto eaque consequuntur ex, illo neque iste repellendus modi, quasi ipsa commodi saepe? Provident ipsa nulla earum.</div>
        </div>
    </div> --}}
</div>
