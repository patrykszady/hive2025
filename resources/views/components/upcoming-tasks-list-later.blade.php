{{--
    Shared "Later" tasks accordion used by every task list surface
    (client schedule, dashboard, planner, Confirm Tasks modal).

    Renders tasks scheduled beyond the displayed window, grouped by date,
    using the same task-card component as the rest of the app.
--}}
@php
    $showAvatars = $showAvatars ?? true;
    $clickable = $clickable ?? true;
    $showProjectInfo = $showProjectInfo ?? false;
    $showVendorInfo = $showVendorInfo ?? true;
    $publicView = $publicView ?? false;
    $expanded = $expanded ?? false;
@endphp

@if(!empty($laterTasks) && $laterTasks->isNotEmpty())
    <flux:accordion transition>
        <flux:accordion.item :expanded="$expanded">
            <flux:accordion.heading>
                <div class="flex items-center gap-2">
                    <span class="text-amber-600 dark:text-amber-400">Later</span>
                    <flux:badge color="amber" size="sm">{{ $laterTasks->flatten()->count() }}</flux:badge>
                    @php
                        $nextLaterDate = \Carbon\Carbon::parse($laterTasks->keys()->first());
                        $daysUntil = (int) now()->startOfDay()->diffInDays($nextLaterDate, false);
                    @endphp
                    <span class="text-xs text-zinc-400 dark:text-zinc-500 italic">
                        Next task in {{ $daysUntil }} {{ Str::plural('day', $daysUntil) }} ({{ $nextLaterDate->format('D, M j') }})
                    </span>
                </div>
            </flux:accordion.heading>
            <flux:accordion.content>
                <div class="space-y-4">
                    @foreach($laterTasks as $date => $tasks)
                        @php
                            $carbonDate = \Carbon\Carbon::parse($date);
                        @endphp
                        <div class="space-y-2">
                            <div class="flex items-center gap-2 min-h-6">
                                <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">
                                    {{ $carbonDate->format('D, M j') }}
                                </flux:heading>
                            </div>

                            @include('components.upcoming-tasks-list-tasks', [
                                'tasks' => $tasks,
                                'date' => $date,
                                'carbonDate' => $carbonDate,
                                'showAvatars' => $showAvatars,
                                'clickable' => $clickable,
                                'showProjectInfo' => $showProjectInfo,
                                'showVendorInfo' => $showVendorInfo,
                                'publicView' => $publicView,
                            ])
                        </div>
                    @endforeach
                </div>
            </flux:accordion.content>
        </flux:accordion.item>
    </flux:accordion>
@endif
