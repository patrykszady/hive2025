<div class="min-h-screen bg-zinc-100 dark:bg-zinc-900">
    {{-- Header --}}
    <div class="border-b border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="max-w-lg mx-auto flex items-center gap-2.5">
            <a href="{{ route('welcome.homeowners') }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-2.5">
                <x-hive-logo class="size-6 shrink-0" />
                <span class="text-base font-semibold text-zinc-900 dark:text-white">Hive Contractors</span>
            </a>
            @if($valid && $project = $this->getProject())
                <span class="ml-auto text-sm text-zinc-500 dark:text-zinc-400">Hi {{ $project->client?->first_names ?? 'there' }}!</span>
            @endif
        </div>
    </div>

    <div class="max-w-lg mx-auto px-4 py-6">
        {{-- Skeleton shown during Livewire refresh (e.g. browser timezone sync) --}}
        <div wire:loading.delay.shortest class="animate-pulse">
            <x-upcoming-tasks-list-skeleton
                title="Tasks"
                :show-project-info="false"
            />
        </div>

        <div wire:loading.delay.shortest.remove>
        @if(!$valid)
            {{-- Invalid Token --}}
            <flux:card class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 bg-zinc-100 dark:bg-zinc-700 rounded-full flex items-center justify-center">
                    <flux:icon.exclamation-triangle class="size-8 text-zinc-400" />
                </div>
                <flux:text class="text-zinc-600 dark:text-zinc-400">{{ $message }}</flux:text>
            </flux:card>
        @else
            @if($this->isServiceCall && $this->unscheduledTasks->isNotEmpty())
                @include('livewire.client.partials.service-availability')
            @endif

            @if($this->groupedTasks->isEmpty())
                {{-- No Upcoming Tasks --}}
                <flux:card class="text-center">
                    <div class="w-16 h-16 mx-auto mb-4 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center">
                        <flux:icon.check class="size-8 text-green-600 dark:text-green-400" />
                    </div>
                    <flux:text class="text-zinc-600 dark:text-zinc-400">No tasks upcoming.</flux:text>
                </flux:card>
            @else
                <x-upcoming-tasks-list
                    :grouped-tasks="$this->groupedTasks"
                    :later-tasks="$this->laterTasks"
                    :task-count="$this->taskCount"
                    :unscheduled-tasks="$this->unscheduledTasks"
                    :pending-tasks-expanded="false"
                    :later-tasks-expanded="true"
                    :show-avatars="false"
                    :clickable="false"
                    :show-project-info="$this->hasMultipleProjects"
                    :show-notifications="false"
                    :public-view="true"
                />

                {{-- Registration CTA --}}
                @guest
                    <div class="mt-6 rounded-xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-900/20 p-5 text-center">
                        <a href="{{ route('welcome.homeowners') }}" target="_blank" rel="noopener noreferrer" class="mx-auto mb-3 flex size-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900/40">
                            <x-hive-logo class="size-7" />
                        </a>
                        <flux:heading size="sm" class="text-indigo-900 dark:text-indigo-100">Join your Project Hive</flux:heading>
                        <flux:text class="mt-1 text-sm text-indigo-700 dark:text-indigo-300">Register a Hive account to get schedule updates, notifications, and project details.</flux:text>
                        <div class="mt-4 flex items-center justify-center gap-3">
                            <flux:button variant="primary" href="{{ route('registration') }}">
                                Register
                            </flux:button>
                            <flux:button href="{{ route('login') }}">
                                Login
                            </flux:button>
                        </div>
                    </div>
                @endguest
            @endif
        @endif

        {{-- Footer --}}
        <x-public-schedule-footer />
        </div>
    </div>
</div>

