@php($isProjectScoped = request()->routeIs('projects.*'))
@php($columnCount = $isProjectScoped ? 6 : 7)

@if($isProjectScoped)
    {{-- No waivers = header-only card with no chevron, exactly what the loaded
         card renders (x-details.card drops the toggle when :accordion is
         false). --}}
    <x-details.card :enter="true" title="Lien Waivers" :expanded="false" :details_text="false" :separator="false" :accordion="$hasWaivers ?? true">
        {{-- Slot forwarded unconditionally (wrapping <x-slot> in @if breaks the
             capture); :accordion above is what removes the chevron, and the
             guard inside skips the shimmer rows. --}}
        <x-slot:details>
            @if($hasWaivers ?? true)
            <div class="space-y-3">
                <div class="grid gap-4 px-4 py-3 border-b border-zinc-200 dark:border-zinc-700" style="grid-template-columns: repeat({{ $columnCount }}, minmax(0, 1fr));">
                    @for ($i = 0; $i < $columnCount; $i++)
                        <div class="h-4 bg-zinc-200 dark:bg-zinc-700 rounded w-full"></div>
                    @endfor
                </div>

                @for ($i = 0; $i < 3; $i++)
                    <div class="grid gap-4 px-4 py-4 border-b border-zinc-100 dark:border-zinc-800" style="grid-template-columns: repeat({{ $columnCount }}, minmax(0, 1fr));">
                        @for ($j = 0; $j < $columnCount; $j++)
                            <div class="h-4 bg-zinc-100 dark:bg-zinc-800 rounded {{ $j === $columnCount - 1 ? 'w-1/2' : 'w-full' }} animate-pulse"></div>
                        @endfor
                    </div>
                @endfor
            </div>
            @endif
        </x-slot:details>
    </x-details.card>
@else
    <div class="w-full max-w-3xl">
        <x-island-card :enter="true" heading="Lien Waivers">
            <div class="space-y-3">
                <div class="grid gap-4 px-4 py-3 border-b border-zinc-200 dark:border-zinc-700" style="grid-template-columns: repeat({{ $columnCount }}, minmax(0, 1fr));">
                    @for ($i = 0; $i < $columnCount; $i++)
                        <div class="h-4 bg-zinc-200 dark:bg-zinc-700 rounded w-full"></div>
                    @endfor
                </div>

                @for ($i = 0; $i < 3; $i++)
                    <div class="grid gap-4 px-4 py-4 border-b border-zinc-100 dark:border-zinc-800" style="grid-template-columns: repeat({{ $columnCount }}, minmax(0, 1fr));">
                        @for ($j = 0; $j < $columnCount; $j++)
                            <div class="h-4 bg-zinc-100 dark:bg-zinc-800 rounded {{ $j === $columnCount - 1 ? 'w-1/2' : 'w-full' }} animate-pulse"></div>
                        @endfor
                    </div>
                @endfor
            </div>
        </x-island-card>
    </div>
@endif
