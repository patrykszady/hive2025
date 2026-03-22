<div class="min-h-screen bg-zinc-100 dark:bg-zinc-900">
    {{-- Header --}}
    <div class="bg-white dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700 px-4 py-6">
        <div class="max-w-lg mx-auto text-center">
            <flux:heading size="xl">Project Schedule</flux:heading>
            @if($valid && $project = $this->getProject())
                <flux:subheading class="mt-1">{{ $project->client?->first_names ?? 'Your Project' }}</flux:subheading>
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
        @elseif($this->groupedTasks->isEmpty())
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
                :next-task-info="$this->nextTaskInfo"
                :task-count="$this->taskCount"
                :unscheduled-tasks="$this->unscheduledTasks"
                :show-avatars="false"
                :clickable="false"
                :show-project-info="$this->hasMultipleProjects"
                :show-notifications="false"
                :public-view="true"
            />

            {{-- Registration CTA --}}
            @guest
                <div class="mt-6 rounded-xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-900/20 p-5 text-center">
                    <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900/40">
                        <img src="{{ asset('favicon.svg') }}" alt="Hive" class="size-7" />
                    </div>
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

        {{-- Footer --}}
        <div class="text-center mt-8 space-y-2">
            <div class="text-xs text-zinc-400">
                Schedule subject to change.
            </div>
            <a href="{{ url('/') }}" class="inline-flex items-center gap-1.5 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors">
                <img src="{{ asset('favicon.svg') }}" alt="Hive" class="size-4" />
                <span>Powered by Hive Contractors</span>
            </a>
        </div>
        </div>
    </div>
</div>

