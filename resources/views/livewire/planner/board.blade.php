<div>
    <div class="flex gap-4 m-8">
        @foreach ($projects as $project)
            <div>
                <div class="rounded-lg w-64 bg-white my-4">
                    <!-- Sticky Project Header -->
                    <div class="sticky top-0 bg-white z-10">
                        <div class="px-4 py-4 flex justify-between items-start">
                            <div>
                                <flux:heading>
                                    <a href="{{ route('projects.show', $project->id) }}" target="_blank">
                                        {{ $project->address }}
                                    </a>
                                </flux:heading>
                                <flux:subheading>{{ $project->client->name }}</flux:subheading>
                                <div class="flex items-center justify-start space-x-1">
                                    <flux:badge size="sm" :color="$project->latestStatus->title == 'Complete' ? 'green' : ($project->latestStatus->title == 'Active' ? 'blue' : ($project->latestStatus->title == 'Cancelled' ? 'red' : 'yellow'))">{{ $project->latestStatus->title }}</flux:badge>
                                    <flux:text size="sm"><i>{{ $project->latestStatus->start_date->diffForHumans() }}</i></flux:text>
                                </div>
                            </div>
                            <flux:button
                                variant="subtle" icon="plus" size="sm"
                                wire:click="$dispatchTo('tasks.task-create', 'addTask', { project_id: {{$project->id}} })"
                            />
                        </div>
                    </div>

                    <!-- Tasks for Each Day -->
                    <div class="flex flex-col gap-2 px-2">
                        @foreach($days as $day)
                            <!-- Sticky Date Header -->
                            <div class="sticky top-[104px] bg-white border-b border-dashed border-{{ $day->isToday() ? 'indigo' : 'gray' }}-{{!$day->isWeekend() ? '300' : '200'}} z-10">
                                <h4 class="text-sm font-semibold px-2 py-1 text-{{ $day->isToday() ? 'indigo' : 'gray' }}-{{!$day->isWeekend() ? '500' : '300'}}">
                                    {{ $day->format('D, M j') }}
                                </h4>
                            </div>

                            <!-- Tasks for the Day -->
                            <div
                                class="grid"
                                style="grid-template-rows: repeat({{ $maxTasksPerDate[$day->format('Y-m-d')] ?? 1 }}, 70px);"
                                >
                                @if (isset($project->grouped_tasks[$day->format('Y-m-d')]))
                                    @foreach ($project->grouped_tasks[$day->format('Y-m-d')] as $task)
                                        @if (isset($task))
                                            @include('livewire.planner._task_card')
                                        @else
                                            <!-- Empty space for null tasks -->
                                            <div class="bg-transparent border border-transparent p-3"></div>
                                        @endif
                                    @endforeach
                                @endif

                                <!-- Render additional empty divs if tasks are fewer than maxTasksPerDate -->
                                @for ($i = (isset($project->grouped_tasks[$day->format('Y-m-d')]) ? $project->grouped_tasks[$day->format('Y-m-d')]->count() : 0); $i < ($maxTasksPerDate[$day->format('Y-m-d')] ?? 1); $i++)
                                    <div class="bg-transparent border border-transparent p-3"></div>
                                @endfor
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    <livewire:tasks.task-create :projects="$projects" :employees="$employees" :vendors="$vendors" />
</div>
