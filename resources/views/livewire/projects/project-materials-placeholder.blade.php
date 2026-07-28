{{-- Materials loading skeleton. The real card is an accordion list, not a
     table, so it can't use <x-index-table.placeholder> — but it follows the
     same contract: real header controls (never a skeleton pill), and a row
     count passed by the component from a cheap COUNT, so an empty card never
     flashes fake rows. --}}
<div>
    <flux:card class="!px-5 !py-2">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">Materials</flux:heading>
            @if($project)
                @can('update', $project)
                    <flux:button size="sm" icon="plus" disabled>
                        Upload
                    </flux:button>
                @endcan
            @endif
        </div>

        @if(($rows ?? 0) > 0)
            <div class="mt-3">
                <flux:skeleton.group animate="shimmer" class="space-y-0 -mt-2">
                    @for ($i = 0; $i < $rows; $i++)
                        <div class="border-b border-zinc-800/10 py-4 first:pt-0 last:border-b-0 last:pb-0 dark:border-white/10">
                            <div class="flex w-full items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <flux:skeleton class="size-5 rounded" />
                                    <flux:skeleton.line class="w-40" />
                                    <flux:skeleton class="h-5 w-14 rounded-md" />
                                </div>
                                <flux:skeleton class="size-5 rounded" />
                            </div>
                        </div>
                    @endfor
                </flux:skeleton.group>
            </div>
        @endif
    </flux:card>
</div>
