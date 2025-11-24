{{-- PROJECT LIFESPAN / STATUS --}}
<flux:card class="space-y-6">
    <flux:accordion transition>
        <flux:accordion.item expanded>
            <flux:accordion.heading>
                <flux:heading size="lg" class="mb-0">Project Lifespan</flux:heading>
            </flux:accordion.heading>

            <flux:accordion.content>
                <ul role="list" class="mt-6">
                    @foreach($statuses as $status)
                        <li class="relative flex gap-x-4 pb-1">
                            @if(!$loop->last)
                                <div class="absolute top-0 left-0 flex justify-center w-6 -bottom-1">
                                    <div class="w-px bg-gray-200"></div>
                                </div>
                            @endif
                            <div class="relative flex items-center justify-center flex-none w-6 h-6 bg-white">
                                @if($loop->last)
                                    <div class="h-6 w-6 rounded-full flex items-center justify-center opacity-30" style="border: 2px solid {{ $status->dotColor }}">
                                        <div class="h-2.5 w-2.5 rounded-full opacity-100" style="background-color: {{ $status->dotColor }}"></div>
                                    </div>
                                @else
                                    <div class="h-1.5 w-1.5 rounded-full bg-gray-100 ring-1 ring-gray-300"></div>
                                @endif
                            </div>
                            <div class="flex-auto py-0.5">
                                <div class="flex items-center gap-2">
                                    <flux:badge size="sm" :color="$status->badgeColor">{{ $status->title }}</flux:badge>
                                    <span class="text-xs text-gray-500">{{$status->start_date->format('m/d/y')}}</span>
                                </div>
                                
                                @if($loop->index < count($statuses) - 1)
                                    @php
                                        $nextStatus = $statuses[$loop->index + 1];
                                        $diffInMinutes = floor(abs($nextStatus->created_at->diffInMinutes($status->created_at)));
                                        $diffInHours = floor($diffInMinutes / 60);
                                        $diffInDays = floor($diffInMinutes / 1440);
                                        
                                        if ($diffInDays > 0) {
                                            $timeText = $diffInDays . ' day' . ($diffInDays === 1 ? '' : 's') . ' later';
                                        } elseif ($diffInHours > 0) {
                                            $remainingMinutes = $diffInMinutes % 60;
                                            $timeText = $diffInHours . ' hour' . ($diffInHours === 1 ? '' : 's');
                                            if ($remainingMinutes > 0) {
                                                $timeText .= ', ' . $remainingMinutes . ' minute' . ($remainingMinutes === 1 ? '' : 's');
                                            }
                                            $timeText .= ' later';
                                        } elseif ($diffInMinutes > 0) {
                                            $timeText = $diffInMinutes . ' minute' . ($diffInMinutes === 1 ? '' : 's') . ' later';
                                        } else {
                                            $timeText = 'less than a minute later';
                                        }
                                    @endphp
                                    <div class="text-xs italic text-gray-400 pl-4">{{ $timeText }}</div>
                                @endif
                            </div>
                            <time datetime="{{$status->created_at}}" class="flex-none py-0.5 text-xs leading-5 text-gray-500">
                                @php
                                    $diffInMinutes = floor($status->created_at->diffInMinutes());
                                    if ($diffInMinutes < 60) {
                                        echo $diffInMinutes . ' minute' . ($diffInMinutes === 1 ? '' : 's') . ' ago';
                                    } else {
                                        echo $status->created_at->diffForHumans();
                                    }
                                @endphp
                            </time>
                        </li>
                    @endforeach
                    
                    @php
                        $lastStatus = $statuses->last();
                        $diffInMinutes = floor(abs(now()->diffInMinutes($lastStatus->created_at)));
                        $diffInHours = floor($diffInMinutes / 60);
                        $diffInDays = floor($diffInMinutes / 1440);
                        
                        if ($diffInDays > 0) {
                            $timeText = $diffInDays . ' day' . ($diffInDays === 1 ? '' : 's') . ' later';
                        } elseif ($diffInHours > 0) {
                            $remainingMinutes = $diffInMinutes % 60;
                            $timeText = $diffInHours . ' hour' . ($diffInHours === 1 ? '' : 's');
                            if ($remainingMinutes > 0) {
                                $timeText .= ', ' . $remainingMinutes . ' minute' . ($remainingMinutes === 1 ? '' : 's');
                            }
                            $timeText .= ' later';
                        } elseif ($diffInMinutes > 0) {
                            $timeText = $diffInMinutes . ' minute' . ($diffInMinutes === 1 ? '' : 's') . ' later';
                        } else {
                            $timeText = 'less than a minute later';
                        }
                    @endphp
                    
                    <li class="relative flex gap-x-4 pb-1">
                        <div class="absolute top-0 left-0 flex justify-center w-6 -bottom-1">
                            <div class="w-px bg-gray-200"></div>
                        </div>
                        <div class="relative flex items-center justify-center flex-none w-6 h-6 bg-white">
                        </div>
                        <div class="flex-auto py-0.5">
                            <div class="text-xs italic text-gray-400 pl-4">{{ $timeText }}</div>
                        </div>
                    </li>
                    
                    <li class="relative flex gap-x-4 pt-1">
                        <div class="relative flex items-center justify-center flex-none w-6 h-6 bg-white">
                            <div class="h-1.5 w-1.5 rounded-full bg-gray-100 ring-1 ring-gray-300"></div>
                        </div>
                        <div class="flex-auto">
                            @include('livewire.project-status._status_controls')
                        </div>
                    </li>

                </ul>
            </flux:accordion.content>
        </flux:accordion.item>
    </flux:accordion>
</flux:card>
