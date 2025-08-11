{{-- <x-task-dependency-card :dependency="$dependency" mode="predecessor" /> --}}
<div wire:key="dependency-{{ $dependency->id }}">
    <flux:card
        class="group shadow hover:shadow-md p-3 !border-2 
        {{ $isBlocking 
            ? '!bg-red-50/50 hover:!bg-red-100/70 !border-dashed !border-red-500' 
            : 'bg-blue-50/50 hover:bg-blue-100/70 !border-blue-500' 
        }}"
    >
        <!-- Card Content -->
        <div class="flex items-start justify-between">
            <!-- Main Content -->
            <div class="flex-grow min-w-0">
                <!-- Title -->
                <flux:heading size="sm" class="{{ $isBlocking ? '!text-red-800' : 'text-blue-700' }}">
                    {{ $task->title }}
                </flux:heading>
                
                <!-- Dependency Type -->
                <flux:text size="xs" class="{{ $isBlocking ? '!text-red-700' : '!text-gray-600' }}">
                    {{ ucfirst(str_replace('_', ' to ', $dependency->type)) }}
                    @if($dependency->lag_days != 0)
                        • {{ $dependency->lag_days > 0 ? '+' : '' }}{{ $dependency->lag_days }} days
                    @endif
                    @if($isBlocking)
                        <flux:badge size="xs" color="red">Blocking</flux:badge>
                    @endif
                </flux:text>
                
                <!-- Date Range -->
                @if($task->start_date && $task->end_date)
                    <flux:text size="xs" class="{{ $isBlocking ? '!text-red-600' : 'text-gray-500' }}">
                        {{ $task->start_date->format('M j') }} - {{ $task->end_date->format('M j') }}
                    </flux:text>
                @endif
                
                <!-- People & Vendor - Fixed layout to prevent disappearing elements -->
                <div class="flex items-center gap-2 mt-1">
                    <!-- Users - Only show container if there are users -->
                    @if(isset($task->user_ids) && !empty($task->user_ids) && $task->users->count() > 0)
                        <div class="flex-shrink-0 flex items-center">
                            @if($task->users->count() === 1)
                                @foreach($task->users as $user)
                                    <flux:avatar
                                        size="xs"
                                        name="{{ $user->full_name }}"
                                        color="auto"
                                        color:seed="{{ $user->id }}"
                                    />
                                @endforeach
                            @else
                                <flux:avatar.group class="**:ring-zinc-50 dark:**:ring-zinc-800">
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
                        </div>
                    @endif
                    
                    <!-- Vendor -->
                    @if($task->vendor)
                        <div class="flex items-center gap-1 min-w-0 overflow-hidden">
                            <flux:avatar
                                size="xs"
                                name="{{ $task->vendor->name }}"
                                color="auto"
                                color:seed="{{ $task->vendor->id }}"
                                class="flex-shrink-0"
                            />
                            <span class="text-sm text-gray-500 truncate max-w-full">
                                {{ $task->vendor->name }}
                            </span>
                        </div>
                    @endif
                </div>
            </div>
            
            <!-- Actions -->
            <div class="flex items-start gap-1 ml-2 opacity-30 group-hover:opacity-100 flex-shrink-0">
                <flux:button
                    wire:click="viewDependentTask({{ $task->id }})"
                    variant="ghost"
                    size="sm"
                    icon="eye"
                    class="text-gray-700 hover:text-gray-900"
                />
                
                <flux:button
                    wire:click="removeDependency({{ $dependency->id }})"
                    variant="ghost"
                    size="sm"
                    icon="link-slash"
                    class="{{ $isBlocking ? 'text-red-700 hover:text-red-900' : 'text-red-600 hover:text-red-800' }}"
                />
            </div>
        </div>
    </flux:card>
</div>