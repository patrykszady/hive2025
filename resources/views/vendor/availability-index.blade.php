@section('title', 'Confirm Your Availability')
<x-guest-layout>
    <div class="min-h-screen bg-zinc-100 dark:bg-zinc-900">
        {{-- Header --}}
        <div class="bg-white dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700 px-4 py-6">
            <div class="max-w-lg mx-auto text-center">
                <flux:heading size="xl">Task Availability</flux:heading>
                @if($valid && $vendor)
                    <flux:subheading class="mt-1">Hi {{ $vendor->short_name ?? $vendor->name }}!</flux:subheading>
                @endif
            </div>
        </div>

        <div class="max-w-lg mx-auto px-4 py-6">
            {{-- Flash Messages --}}
            @if(session('success'))
                <div class="mb-4">
                    <flux:callout variant="success" icon="check-circle">
                        {{ session('success') }}
                    </flux:callout>
                </div>
            @endif

            @if(session('error'))
                <div class="mb-4">
                    <flux:callout variant="danger" icon="exclamation-circle">
                        {{ session('error') }}
                    </flux:callout>
                </div>
            @endif

            @if(!$valid)
                {{-- Invalid Token --}}
                <flux:card class="text-center">
                    <div class="w-16 h-16 mx-auto mb-4 bg-zinc-100 dark:bg-zinc-700 rounded-full flex items-center justify-center">
                        <flux:icon.exclamation-triangle class="size-8 text-zinc-400" />
                    </div>
                    <flux:text class="text-zinc-600 dark:text-zinc-400">{{ $message }}</flux:text>
                </flux:card>
            @elseif($tasks->isEmpty())
                {{-- No Pending Tasks --}}
                <flux:card class="text-center">
                    <div class="w-16 h-16 mx-auto mb-4 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center">
                        <flux:icon.check class="size-8 text-green-600 dark:text-green-400" />
                    </div>
                    <flux:text class="text-zinc-600 dark:text-zinc-400">All done! No pending tasks to confirm.</flux:text>
                </flux:card>
            @else
                {{-- Pending Tasks List --}}
                <div class="space-y-3">
                    @foreach($tasks as $task)
                        <div class="bg-white dark:bg-zinc-900 rounded-lg shadow-sm border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                            {{-- Task Card Content (like planner kanban card) --}}
                            <div class="p-3">
                                <div class="flex items-start justify-between gap-2 min-w-0">
                                    <flux:heading size="sm" class="min-w-0 truncate">
                                        {{ $task->title }}
                                    </flux:heading>
                                    @if($task->vendor_status === 'confirmed')
                                        <flux:badge color="green" size="sm" icon="check">Confirmed</flux:badge>
                                    @elseif($task->vendor_status === 'rejected')
                                        <flux:badge color="red" size="sm" icon="x-mark">Declined</flux:badge>
                                    @else
                                        <flux:badge color="yellow" size="sm">Pending</flux:badge>
                                    @endif
                                </div>

                                {{-- Project & Date Info --}}
                                <div class="mt-2 space-y-1">
                                    @if($task->project?->address)
                                        <a 
                                            href="{{ $task->project->getAddressMapURI() }}" 
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="flex items-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-200 transition-colors"
                                        >
                                            <flux:icon.map-pin class="size-4 shrink-0" />
                                            <span class="truncate">{{ $task->project->address }}</span>
                                        </a>

                                        @php
                                            $cityState = collect([
                                                $task->project?->city,
                                                $task->project?->state,
                                            ])->filter()->implode(', ');

                                            $cityStateZip = trim(implode(' ', array_filter([
                                                $cityState,
                                                $task->project?->zip_code,
                                            ])));
                                        @endphp

                                        @if($cityStateZip)
                                            <div class="text-sm text-zinc-600 dark:text-zinc-400">
                                                {{ $cityStateZip }}
                                            </div>
                                        @endif
                                    @else
                                        <flux:subheading class="truncate">No location</flux:subheading>
                                    @endif
                                    <div class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                        <flux:icon.clock class="size-4 shrink-0" />
                                        <span>{{ $task->date_with_time }}</span>

                                        @if($task->start_date?->isTomorrow())
                                            <flux:badge size="xs" inset="top bottom" color="sky">Tomorrow</flux:badge>
                                        @endif
                                    </div>
                                </div>

                                {{-- Owner Avatar Row (like vendor row in planner) --}}
                                @if($task->owner)
                                    <div class="flex items-center gap-2 mt-3 min-w-0">
                                        <flux:avatar
                                            circle
                                            size="xs"
                                            name="{{ $task->owner->full_name ?? $task->owner->name }}"
                                            color="auto"
                                            color:seed="{{ $task->owner->id }}"
                                        />
                                        <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">
                                            {{ $task->owner->short_name ?? $task->owner->name }}
                                        </span>
                                    </div>
                                @endif
                            </div>

                            {{-- Action Buttons --}}
                            @if($task->vendor_status === 'requested')
                                <div class="flex border-t border-zinc-200 dark:border-zinc-700">
                                    <a href="{{ route('vendor.availability.confirm', [$token, $task->id]) }}" 
                                       class="flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/20 transition-colors border-r border-zinc-200 dark:border-zinc-700">
                                        <flux:icon.check class="size-5" />
                                        I'm Available
                                    </a>
                                    <a href="{{ route('vendor.availability.reject', [$token, $task->id]) }}" 
                                       class="flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                                        <flux:icon.x-mark class="size-5" />
                                        Not Available
                                    </a>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Footer --}}
            <div class="text-center mt-8">
                <a href="{{ url('/') }}" class="inline-flex items-center gap-1.5 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors">
                    <img src="{{ asset('favicon.png') }}" alt="Hive" class="size-4" />
                    <span>Powered by Hive Contractors</span>
                </a>
            </div>
        </div>
    </div>
</x-guest-layout>
