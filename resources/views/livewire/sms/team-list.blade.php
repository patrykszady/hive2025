<div class="h-full overflow-y-auto" data-team-list>
    @forelse ($this->rows as $row)
        <div
            wire:key="team-row-{{ $row['key'] }}"
            class="group flex items-center gap-2 rounded-lg px-3 py-2.5"
            x-bind:class="{{ $row['thread_id'] ? '$store.sms.threadId === '.$row['thread_id'] : 'false' }}
                ? 'bg-zinc-100 dark:bg-zinc-700'
                : 'hover:bg-zinc-50 dark:hover:bg-zinc-800'"
            data-team-row="{{ $row['key'] }}"
        >
            <button
                type="button"
                wire:click="{{ $row['is_group'] ? 'openGroup' : 'openTeammate('.$row['user_id'].')' }}"
                class="flex min-w-0 flex-1 items-center gap-3 text-left"
                title="Message {{ $row['title'] }} in Hive"
            >
                <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-full {{ $row['is_group'] ? 'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300' }} text-sm font-semibold">
                    @if ($row['is_group'])
                        <flux:icon name="user-group" class="size-4" />
                    @else
                        {{ $row['initial'] }}
                    @endif
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center justify-between gap-2">
                        <span class="truncate text-base font-medium text-zinc-900 lg:text-sm dark:text-zinc-100 {{ $row['unread'] ? 'font-semibold' : '' }}">{{ $row['title'] }}</span>
                        @if ($row['at'])
                            <span class="shrink-0 text-xs text-zinc-400 dark:text-zinc-500">{{ $row['at']->year !== now()->year ? $row['at']->format('M j, Y') : $row['at']->diffForHumans(short: true) }}</span>
                        @endif
                    </span>
                    <span class="flex items-center gap-1.5">
                        @if ($row['unread'])
                            <span class="size-2 shrink-0 rounded-full bg-blue-500" data-team-unread></span>
                        @endif
                        <span class="truncate text-sm text-zinc-500 lg:text-xs dark:text-zinc-400 {{ $row['unread'] ? 'text-zinc-800 dark:text-zinc-200' : '' }}">
                            {{ $row['preview'] ?? ($row['is_group'] ? 'Everyone at '.rtrim(str_replace(' Team', '', $row['title'])) : 'Message or call in Hive') }}
                        </span>
                    </span>
                </span>
            </button>
            @if ($this->canCall)
                {{-- Rings their Hive — or everyone's, on the crew row (TeamPhone listens); recorded like any other call. --}}
                <button
                    type="button"
                    class="inline-flex size-8 shrink-0 items-center justify-center rounded-full text-green-700 hover:bg-green-100 dark:text-green-300 dark:hover:bg-green-900/40"
                    title="Call {{ $row['is_group'] ? 'the crew' : $row['title'] }} in Hive"
                    x-on:click.stop="window.dispatchEvent(new CustomEvent({{ $row['is_group'] ? "'team-call-crew'" : "'team-call', { detail: { userId: ".$row['user_id']." } }" }}))"
                    @if ($row['is_group']) data-team-call-crew @else data-team-call="{{ $row['user_id'] }}" @endif
                >
                    <flux:icon name="phone" class="size-4" />
                </button>
            @endif
        </div>
    @empty
        <div class="px-3 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
            Nobody else on the team yet.
        </div>
    @endforelse
</div>
