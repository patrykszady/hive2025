@section('title', 'Availability Response')
<x-guest-layout>
    <div class="flex min-h-screen items-center justify-center bg-gray-100 dark:bg-gray-900 px-4">
        <div class="w-full max-w-md rounded-lg bg-white dark:bg-gray-800 p-8 shadow-lg text-center">
            @if($success)
                @if($action === 'confirmed')
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900">
                        <svg class="h-8 w-8 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <h1 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">Confirmed!</h1>
                @else
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900">
                        <svg class="h-8 w-8 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </div>
                    <h1 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">Response Received</h1>
                @endif
            @else
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                    <svg class="h-8 w-8 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
                <h1 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">Link Expired</h1>
            @endif

            <p class="text-gray-600 dark:text-gray-300">{{ $message }}</p>

            @if($success && isset($task))
                <div class="mt-6 rounded-lg bg-gray-50 dark:bg-gray-700 p-4 text-left">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Task</p>
                    <p class="font-medium text-gray-900 dark:text-white">{{ $task->title }}</p>
                    
                    @if($task->project?->address)
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Project</p>
                        <p class="font-medium text-gray-900 dark:text-white">{{ $task->project->address }}</p>
                    @endif
                    
                    @if($task->start_date)
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Date</p>
                        <p class="font-medium text-gray-900 dark:text-white">
                            {{ $task->start_date->format('M j, Y') }}
                            @if($task->end_date && !$task->start_date->eq($task->end_date))
                                - {{ $task->end_date->format('M j, Y') }}
                            @endif
                        </p>
                    @endif
                </div>
            @endif

            <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">
                You can close this page now.
            </p>
        </div>
    </div>
</x-guest-layout>
