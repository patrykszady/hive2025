<!-- Single Kanban Card -->
<div class="h-full">
    <flux:card
        class="mx-auto h-full overflow-auto p-0 isolate"
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
                            block: 'center',
                            inline: 'nearest'
                        });
                    } else {
                        // If no previous sibling (today is first day), scroll to today
                        todayElement.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center',
                            inline: 'nearest'
                        });
                    }
                }
            }
        }"
        x-init="$nextTick(() => scrollToToday())"
    >
        <!-- Header - Sticky at top -->
        <div class="sticky top-0 bg-white z-50 shadow-sm">
            <div class="flex items-start justify-between gap-4 p-4">
                <flux:heading size="lg">{{ $headerTitle }}</flux:heading>

                {{-- Show Add Task button based on type and authorization --}}
                @if(
                    ($type === 'project' && $project && $this->can('update', $project)) ||
                    ($type === 'vendor' && $vendor) ||
                    ($type === 'employee' && $employee)
                )
                    <div class="flex-shrink-0">
                        <flux:button
                            wire:click="addTaskButton"
                            size="sm"
                            icon="plus"
                        >
                            Add Task
                        </flux:button>
                    </div>
                @endif
            </div>
        </div>

        <!-- Days as Rows (including No Date Tasks) -->
        <div class="divide-y divide-gray-200">
            <!-- No Date Tasks Row - Show first if exists -->
            @if($tasksData['noDateTasks']->count() > 0)
                <div class="bg-gray-50 select-none">
                    <!-- No Date Tasks Header - Sticky under main header -->
                    <div class="bg-gray-50 border-b border-gray-200 px-4 py-2 shadow-sm select-none relative z-40">
                        <flux:accordion>
                            <flux:accordion.item expanded>
                                <flux:accordion.heading class="bg-gray-50 px-4 py-2">
                                    <div class="flex items-center justify-between w-full">
                                        <div class="text-gray-700">
                                            <h4 class="font-medium text-sm">Unscheduled Tasks</h4>
                                            <p class="text-xs text-gray-500">Tasks with no dates assigned</p>
                                        </div>
                                        <flux:badge size="sm" color="gray" variant="outline">
                                            {{ $tasksData['noDateTasks']->count() }}
                                        </flux:badge>
                                    </div>
                                </flux:accordion.heading>

                                <flux:accordion.content>
                                    <div class="space-y-3 p-4 bg-gray-50 max-h-60 overflow-y-auto">
                                        @foreach($tasksData['noDateTasks'] as $taskData)
                                            @php
                                                $task = $taskData['task'];
                                                $taskTypeColor = $taskData['taskTypeColor'];
                                            @endphp

                                            <!-- Task Card -->
                                            <div class="bg-white border border-l-4 rounded transition-all hover:bg-gray-50 relative select-none {{ $taskTypeColor === 'blue' ? 'border-blue-500 border-l-blue-500' : 'border-indigo-500 border-l-indigo-500' }}">
                                                <div class="p-3">
                                                    <!-- Project Address (show for employee/vendor view, hide for project view) -->
                                                    @if($task->project && $type !== 'project')
                                                        <a
                                                            href="{{ $task->project->getAddressMapURI() }}"
                                                            target="_blank"
                                                            class="truncate font-medium text-sm text-gray-800 mb-1 block hover:text-blue-600 cursor-pointer flex items-center gap-1 select-none"
                                                            >
                                                            <flux:icon.map-pin class="w-3 h-3" />
                                                            {{ $task->project->address }}
                                                        </a>
                                                    @endif

                                                    <!-- Task Title -->
                                                    <div
                                                        class="truncate italic text-sm text-gray-900 mb-2 cursor-pointer hover:text-blue-600 flex items-center gap-1 select-none"
                                                        wire:click="editTask({{ $task->id }})"
                                                        >
                                                        <flux:icon.pencil-square class="w-3 h-3" />
                                                        {{ $task->title }}
                                                    </div>

                                                    <!-- Users and Vendor -->
                                                    <div class="flex items-center gap-2 min-h-0 h-5 select-none">
                                                        @if($task->users && $task->users->count() > 0 && $type !== 'employee')
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

                                                        @if($task->vendor && $type !== 'vendor')
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
                                            </div>
                                        @endforeach
                                    </div>
                                </flux:accordion.content>
                            </flux:accordion.item>
                        </flux:accordion>
                    </div>
                </div>
            @endif

            <!-- Regular Day Rows -->
            @foreach($tasksData['dayTasks'] as $dayData)
                @php
                    $day = $dayData['day'];
                    $tasks = $dayData['tasks'];
                    $isWeekend = $day->isWeekend();
                @endphp

                <!-- Day Row -->
                <div class="{{ $day->isToday() ? 'bg-blue-50/50' : '' }} select-none" @if($day->isToday()) data-today @endif>
                    <!-- Day Header - Sticky under main header -->
                    <div class="sticky top-[56px] bg-white border-b border-gray-100 px-4 py-2 shadow-sm select-none relative z-30">
                        <div class="flex items-center justify-between">
                            <div class="{{ $day->isToday() ? 'text-blue-600' : ($isWeekend ? 'text-gray-500 italic' : 'text-gray-900') }}">
                                <h4 class="font-medium text-sm">
                                    {{ $day->format('l') }} <!-- Full day name -->
                                </h4>
                                <p class="text-xs {{ $day->isToday() ? 'text-blue-500' : ($isWeekend ? 'text-gray-400 italic' : 'text-gray-600') }}">{{ $day->format('M j, Y') }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Tasks for this day - Only show if there are tasks -->
                    @if($tasks->count() > 0)
                        <div class="space-y-3 p-4 select-none">
                            @foreach($tasks as $taskData)
                                @php
                                    $task = $taskData['task'];
                                    $taskTypeColor = $taskData['taskTypeColor'];
                                @endphp

                                <!-- Task Card -->
                                <div class="bg-white border border-l-4 rounded transition-all hover:bg-gray-50 relative select-none {{ $taskTypeColor === 'blue' ? 'border-blue-500 border-l-blue-500' : 'border-indigo-500 border-l-indigo-500' }}">
                                    <div class="p-3">
                                        <!-- Project Address (show for employee/vendor view, hide for project view) -->
                                        @if($task->project && $type !== 'project')
                                            <a
                                                href="{{ $task->project->getAddressMapURI() }}"
                                                target="_blank"
                                                class="truncate font-medium text-sm text-gray-800 mb-1 block hover:text-blue-600 cursor-pointer flex items-center gap-1 select-none"
                                                >
                                                <flux:icon.map-pin class="w-3 h-3" />
                                                {{ $task->project->address }}
                                            </a>
                                        @endif

                                        <!-- Task Title -->
                                        <div
                                            class="truncate italic text-sm text-gray-900 mb-2 cursor-pointer hover:text-blue-600 flex items-center gap-1 select-none"
                                            wire:click="editTask({{ $task->id }})"
                                            >
                                            <flux:icon.pencil-square class="w-3 h-3" />
                                            {{ $task->title }}
                                        </div>

                                        <!-- Users and Vendor -->
                                        <div class="flex items-center gap-2 min-h-0 h-5 select-none">
                                            @if($task->users && $task->users->count() > 0 && $type !== 'employee')
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

                                            @if($task->vendor && $type !== 'vendor')
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
                                    @if($taskData['totalFamilyDays'] > 1 && $taskData['currentFamilyDay'])
                                        <div class="absolute bottom-1 right-1 text-xs text-gray-400 select-none">
                                            {{ $taskData['currentFamilyDay'] }}/{{ $taskData['totalFamilyDays'] }}
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
    <livewire:tasks.task-create :projects="$projects" :employees="$employees" :vendors="$vendors"/>
</div>
