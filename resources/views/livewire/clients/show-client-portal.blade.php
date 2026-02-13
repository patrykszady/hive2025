<div class="min-h-screen bg-zinc-100 dark:bg-zinc-900">
    {{-- Header --}}
    <div class="bg-white dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700 px-4 py-6">
        <div class="max-w-4xl mx-auto">
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">Welcome back, {{ auth()->user()->first_name }}</flux:heading>
                    <flux:subheading class="mt-1">Here's what's happening with your projects.</flux:subheading>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <flux:button type="submit" variant="ghost" size="sm" icon="arrow-right-start-on-rectangle">
                        Logout
                    </flux:button>
                </form>
            </div>
        </div>
    </div>

    <div class="max-w-4xl mx-auto px-4 py-8 space-y-8">
        {{-- Quick stats --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <flux:card>
                <div class="flex items-center gap-3">
                    <div class="size-10 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg flex items-center justify-center">
                        <flux:icon.folder class="size-5 text-indigo-600 dark:text-indigo-400" />
                    </div>
                    <div>
                        <flux:text class="text-2xl font-semibold">{{ $this->clientProjects->count() }}</flux:text>
                        <flux:text class="text-sm text-zinc-500">{{ Str::plural('Project', $this->clientProjects->count()) }}</flux:text>
                    </div>
                </div>
            </flux:card>

            <flux:card>
                <div class="flex items-center gap-3">
                    <div class="size-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                        <flux:icon.calendar class="size-5 text-green-600 dark:text-green-400" />
                    </div>
                    <div>
                        <flux:text class="text-2xl font-semibold">{{ $this->upcomingTasks->count() }}</flux:text>
                        <flux:text class="text-sm text-zinc-500">Upcoming Tasks</flux:text>
                    </div>
                </div>
            </flux:card>

            <flux:card>
                <div class="flex items-center gap-3">
                    <div class="size-10 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center">
                        <flux:icon.home class="size-5 text-purple-600 dark:text-purple-400" />
                    </div>
                    <div>
                        <flux:text class="text-2xl font-semibold">{{ $client->name }}</flux:text>
                        <flux:text class="text-sm text-zinc-500">Your Account</flux:text>
                    </div>
                </div>
            </flux:card>
        </div>

        {{-- Projects list --}}
        <div>
            <flux:heading size="lg" class="mb-4">Your Projects</flux:heading>

            @if($this->clientProjects->isEmpty())
                <flux:card class="text-center py-12">
                    <div class="w-16 h-16 mx-auto mb-4 bg-zinc-100 dark:bg-zinc-700 rounded-full flex items-center justify-center">
                        <flux:icon.folder class="size-8 text-zinc-400" />
                    </div>
                    <flux:text class="text-zinc-600 dark:text-zinc-400">No projects yet.</flux:text>
                    <flux:text class="text-sm text-zinc-500 mt-1">Your contractor will add projects here.</flux:text>
                </flux:card>
            @else
                <div class="grid gap-4">
                    @foreach($this->clientProjects as $project)
                        <flux:card class="hover:shadow-md transition-shadow">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <flux:heading size="base">{{ $project->address }}</flux:heading>
                                    @if($project->city || $project->state)
                                        <flux:text class="text-sm text-zinc-500">
                                            {{ collect([$project->city, $project->state])->filter()->join(', ') }}
                                        </flux:text>
                                    @endif
                                    
                                    @if($project->tasks->count() > 0)
                                        <div class="mt-3 flex items-center gap-2 text-sm text-zinc-500">
                                            <flux:icon.calendar class="size-4" />
                                            <span>{{ $project->tasks->count() }} upcoming {{ Str::plural('task', $project->tasks->count()) }}</span>
                                        </div>
                                    @endif

                                    @if($project->schedule_token)
                                        <div class="mt-3">
                                            <flux:button 
                                                href="{{ route('client.schedule.index', $project->schedule_token) }}"
                                                variant="primary"
                                                size="sm"
                                                icon="calendar"
                                            >
                                                View Schedule
                                            </flux:button>
                                        </div>
                                    @endif
                                </div>
                                
                                @if($project->latestStatus)
                                    <flux:badge size="sm" color="green">
                                        {{ $project->latestStatus->status_name ?? 'Active' }}
                                    </flux:badge>
                                @endif
                            </div>
                        </flux:card>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Upcoming tasks --}}
        @if($this->upcomingTasks->isNotEmpty())
            <div>
                <flux:heading size="lg" class="mb-4">Upcoming Schedule</flux:heading>
                
                <flux:card>
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach($this->upcomingTasks as $task)
                            <div class="py-3 first:pt-0 last:pb-0">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <flux:text class="font-medium">{{ $task->title }}</flux:text>
                                        <flux:text class="text-sm text-zinc-500">{{ $task->project->short_address ?? $task->project->address }}</flux:text>
                                    </div>
                                    <flux:badge size="sm" color="zinc">
                                        {{ $task->start_date->format('M j') }}
                                    </flux:badge>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </flux:card>
            </div>
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
