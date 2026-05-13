@php($isProjectScoped = request()->routeIs('projects.*'))
@php($columnCount = $isProjectScoped ? 6 : 7)

@if($isProjectScoped)
    <x-details.card title="Lien Waivers" :expanded="false" :details_text="false" :separator="false">
        <x-slot:details>
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
        </x-slot:details>
    </x-details.card>
@else
    <div class="w-full max-w-3xl">
        <x-island-card heading="Lien Waivers">
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
