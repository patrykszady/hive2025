<!-- Modal -->
<div
    x-dialog
    x-model="open"
    style="display: none"
    class="fixed inset-0 z-50 overflow-y-auto"
    >
    <!-- Overlay -->
    <div x-dialog:overlay x-transition.opacity class="fixed inset-0 bg-black bg-opacity-50"></div>

    <!-- Panel -->
    <div class="relative flex items-center justify-center min-h-screen p-4">
        <div
            {{-- x-dialog:panel --}}
            x-transition.in
            x-transition.out.opacity
            {{-- overflow-y-auto --}}
            class="relative w-full max-w-2xl bg-white shadow-lg rounded-xl"
            >
            <!-- Close Button -->

            <!-- Panel -->
            <div>
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
