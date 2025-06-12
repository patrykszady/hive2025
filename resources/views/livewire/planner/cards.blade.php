<!-- Single Kanban Card for Employee -->
<div class="h-full">
    <flux:card
        class="max-w-lg mx-auto h-full overflow-auto p-0 isolate"
        x-data="{
            scrollToToday() {
                // Find today's element
                const todayElement = this.$el.querySelector('[data-today]');
                if (todayElement) {
                    // Get the previous sibling (yesterday)
                    const yesterdayElement = todayElement.previousElementSibling;
                    if (yesterdayElement) {
                        // Scroll to yesterday's element with smooth behavior
                        yesterdayElement.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start',
                            inline: 'nearest'
                        });
                    } else {
                        // If no previous sibling (today is first day), scroll to today
                        todayElement.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start',
                            inline: 'nearest'
                        });
                    }
                }
            }
        }"
        x-init="$nextTick(() => scrollToToday())"
    >
        <!-- Employee Header - Sticky at top within isolated context -->
        <div class="sticky top-0 bg-white relative z-10">
            <flux:heading size="lg" class="p-4">{{ $employee->first_name }}'s Tasks</flux:heading>
            <flux:separator />
        </div>

        <!-- Days as Rows -->
        <div class="divide-y divide-gray-200">
            @foreach($tasksData as $dayData)
                @php
                    $day = $dayData['day'];
                    $tasks = $dayData['tasks'];
                @endphp

                <!-- Day Row -->
                <div class="{{ $day->isToday() ? 'bg-blue-50/50' : '' }} select-none" @if($day->isToday()) data-today @endif>
                    <!-- Day Header - Sticky within isolated context -->
                    <div class="sticky top-[56px] bg-white border-b border-gray-100 px-4 py-2 shadow-sm select-none relative z-[1]">
                        <div class="flex items-center justify-between">
                            <div class="{{ $day->isToday() ? 'text-blue-600' : 'text-gray-900' }}">
                                <h4 class="font-medium text-sm">
                                    {{ $day->format('l') }} <!-- Full day name -->
                                </h4>
                                <p class="text-xs {{ $day->isToday() ? 'text-blue-500' : 'text-gray-600' }}">{{ $day->format('M j, Y') }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Tasks for this day - Only show if there are tasks -->
                    @if($tasks->count() > 0)
                        <div class="space-y-3 p-4 select-none">
                            @foreach($tasks as $task)
                                @php
                                    $taskTypeColor = $task->type === 'Task' ? 'blue' : ($task->type === 'Milestone' ? 'indigo' : 'blue');

                                    // Calculate which day of the task this is
                                    $taskStartDate = \Carbon\Carbon::parse($task->start_date);
                                    $taskEndDate = \Carbon\Carbon::parse($task->end_date);
                                    $totalDays = $taskStartDate->diffInDays($taskEndDate) + 1;
                                    $currentDayNumber = $taskStartDate->diffInDays($day) + 1;
                                @endphp

                                <!-- Task Card -->
                                <div class="bg-white border border-l-4 rounded transition-all hover:bg-gray-50 relative select-none {{ $taskTypeColor === 'blue' ? 'border-blue-500 border-l-blue-500' : 'border-indigo-500 border-l-indigo-500' }}">
                                    <div class="p-3">
                                        <!-- Project Address -->
                                        @if($task->project)
                                            <a
                                                href="{{ $task->project->getAddressMapURI() }}"
                                                target="_blank"
                                                class="font-medium text-sm text-gray-800 mb-1 block hover:text-blue-600 cursor-pointer flex items-center gap-1 select-none"
                                                >
                                                <flux:icon.map-pin class="w-3 h-3" />
                                                {{ $task->project->address }}
                                            </a>
                                        @endif

                                        <!-- Task Title -->
                                        <div
                                            class="italic text-sm text-gray-900 mb-2 cursor-pointer hover:text-blue-600 flex items-center gap-1 select-none"
                                            wire:click="editTask({{ $task->id }})"
                                            >
                                            <flux:icon.pencil-square class="w-3 h-3" />
                                            {{ $task->title }}
                                        </div>

                                        <!-- Users and Vendor -->
                                        <div class="flex items-center gap-2 min-h-0 h-5 select-none">
                                            @if($task->users && $task->users->count() > 0)
                                                <flux:avatar.group size="xs">
                                                    @foreach($task->users as $user)
                                                        <flux:avatar
                                                            size="xs"
                                                            name="{{ $user->full_name }}"
                                                            color="auto"
                                                            color:seed="{{ $user->id }}"
                                                        />
                                                    @endforeach
                                                </flux:avatar.group>
                                            @endif

                                            @if($task->vendor)
                                                <flux:avatar
                                                    size="xs"
                                                    name="{{ $task->vendor->name }}"
                                                    color="auto"
                                                    color:seed="{{ $task->vendor->id }}"
                                                    class="flex-shrink-0"
                                                />
                                                <span class="text-xs min-w-0 whitespace-nowrap truncate text-gray-600">
                                                    {{ $task->vendor->name }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    <!-- Day Indicator -->
                                    @if($totalDays > 1)
                                        <div class="absolute bottom-1 right-1 text-xs text-gray-400 select-none">
                                            {{ $currentDayNumber }}/{{ $totalDays }}
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </flux:card>
    <livewire:tasks.task-create :projects="$this->projects" :employees="$employees" :vendors="$vendors"/>
</div>
