{{-- adopted from https://alpinejs.dev/component/modal --}}
<div x-data="{ sidebarOpen: false }">
    <div
        x-data="{ open: @entangle('modal_show') }"
        x-show="open"
        x-on:open-modal.window = "open = true"
        x-on:close-modal.window = "open = false"
        x-on:keydown.escape.window = "open = false"
        class="flex justify-center"
        >

        <!-- Overlay -->
        <div x-show="open" x-transition.opacity class="fixed inset-0 z-40 bg-black bg-opacity-50"></div>

        <!-- Modal -->
        {{-- x-on:keydown.escape.prevent.stop="open = false" --}}
        @teleport('body')
            <div x-show="open" x-transition.duration.200ms style="display: none" role="dialog"
                aria-modal="true" {{-- x-id="['modaltitle{{ Str::random() }}']" --}} {{-- :aria-labelledby="$id(title)" --}} class="fixed inset-0 z-40 overflow-y-auto">
                <!-- Panel -->
                <div x-show="open" x-transition.duration.200ms x-on:click="open = false"
                    class="relative flex items-center justify-center min-h-screen p-4">
                    <div x-on:click.stop x-trap.noscroll.inert="open"
                        class="relative max-w-2xl w-full bg-white rounded-lg shadow-lg overflow-y-auto {{ $attributes['class'] }}">

                        {{ $slot }}
                    </div>
                </div>
            </div>
        @endteleport
    </div>
</div>
