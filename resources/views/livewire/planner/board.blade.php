<div
    class="h-full opacity-0"
    x-data
    x-init="$nextTick(() => {
        const todayElement = document.querySelector('[data-today]');
        if (todayElement) {
            todayElement.scrollIntoView({ behavior: 'instant', inline: 'start', block: 'nearest' });
        }
        // Show content after scroll position is set
        $el.classList.remove('opacity-0');
        $el.classList.add('opacity-100');
    })"
    >
    <div class="min-w-max">
        <!-- Header Row: Dates only, sticky to top -->
        <div
            class="grid divide-x divide-gray-300 bg-white border-b border-gray-200 sticky top-0"
            style="grid-template-columns: repeat({{ count($days) }}, 1fr);"
            >
            @foreach ($days as $day)
                <div class="p-2 text-left text-sm {{ $day->isToday() ? 'font-bold ' : '' }}" @if($day->isToday()) data-today @endif>
                    {{ $day->format('D, M j') }}
                </div>
            @endforeach
        </div>

        <!-- For each project: project row, then grid of days -->
        @foreach ($projects as $project)
            <!-- Project name row: full width background, sticky project name -->
            <div class="relative bg-gray-50 border-b border-gray-200 sticky top-[37px]">
                <div class="p-2 sticky left-0 w-fit bg-gray-50">
                    {{ $project->address }}
                </div>
            </div>
            <!-- Grid of days for this project -->
            <div class="grid" style="grid-template-columns: repeat({{ count($days) }}, 1fr); min-height: 48px;">
                @foreach ($days as $day)
                    <div class="p-2 border-b border-gray-100 text-left space-y-1">
                        @foreach ($project->grouped_tasks[$day->format('Y-m-d')] as $task)
                            <div class="bg-blue-200 rounded px-2 py-1 text-xs">
                                {{ $task->title }}
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
