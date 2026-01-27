<div>
	<div class="grid max-w-xl grid-cols-4 gap-4 lg:max-w-5xl sm:px-6">
		<div class="col-span-4 lg:col-span-2 space-y-4">
            {{-- PROJECT DETAILS --}}
            <x-details.card
                :title="$project->short_address . ' | ' . $project->project_name"
                :subheading="$project->client->name"
                :canEdit="auth()->user()->can('update', $project)"
                >
                <x-slot:header_buttons>
                    <flux:button
                        wire:click="$dispatchTo('projects.project-create', 'editProject', { project: {{$project->id}}})"
                        size="sm"
                        >
                        Edit Project
                    </flux:button>
                </x-slot:header_buttons>

                <x-slot:details>
                    {{-- Project Client --}}
                    <x-details.row 
                        title="Project Client" 
                        :content="$project->client->name"
                        :href="route('clients.show', $project->client)"
                        :navigate="true"
                    />

                    {{-- Project Name --}}
                    <x-details.row 
                        title="Project Name" 
                        :content="$project->project_name"
                    />

                    @php
                        $jobsiteAddress = $project->full_address;
                        $billingAddress = $project->client->full_address;
                        $sameAddress = $jobsiteAddress === $billingAddress;
                    @endphp

                    @if($sameAddress)
                        {{-- Combined Address when jobsite and billing are the same --}}
                        <x-details.row 
                            title="Address" 
                            :content="$project->full_address" 
                            :href="$project->getAddressMapURI()"
                            :copyable="true"
                        />
                    @else
                        {{-- Jobsite Address --}}
                        <x-details.row 
                            title="Jobsite Address" 
                            :content="$project->full_address" 
                            :href="$project->getAddressMapURI()"
                            :copyable="true"
                        />

                        @can('viewFinancials', $project)
                            {{-- Billing Address --}}
                            <x-details.row 
                                title="Billing Address" 
                                :content="$project->client->full_address"
                                :href="$project->client->getAddressMapURI()"
                                :copyable="true"
                            />
                        @endcan
                    @endif
                </x-slot:details>

                <x-slot:footer>    
                    @can('update', $project)
                        <livewire:projects.project-create />
                    @endcan
                </x-slot:footer>
            </x-details.card>

            @if(in_array($project->latestStatus?->status_code, [4, 5, 6, 8, 11]))
                <livewire:projects.upcoming-tasks :project="$project" lazy />
            @endif

            {{-- PROJECT TIMELINE --}}
            {{-- <div class="h-180">
                <livewire:planner.cards-index type="project" :project-id="$project->id" lazy />
            </div> --}}
		</div>

        @can('viewFinancials', $project)
            <div class="col-span-4 space-y-4 lg:col-span-2 lg:col-start-3">
                {{-- PROJECT ESTIMATES --}}
                @can('viewAny', App\Models\Estimate::class)
                    <livewire:estimates.estimates-index :project="$project" :view="'projects.show'" lazy />
                @endcan

                @can('update', $project)
                    {{-- EMAIL TRACKING --}}
                    <div x-data="{ loaded: false }" x-intersect.once="$wire.loadEmailTracking(); loaded = true">
                        @if(!$showEmailTracking)
                            <flux:card class="space-y-4 animate-pulse">
                                <div class="h-6 bg-zinc-300 dark:bg-zinc-700 rounded w-1/3"></div>
                                <div class="h-px bg-zinc-200 dark:bg-zinc-700"></div>
                                <div class="space-y-3">
                                    @for ($i = 0; $i < 3; $i++)
                                        <div class="h-12 bg-zinc-200 dark:bg-zinc-800 rounded"></div>
                                    @endfor
                                </div>
                            </flux:card>
                        @elseif($this->emailTrackingEvents->count() > 0)
                            <flux:card class="space-y-2">
                            <div class="flex justify-between items-center">
                                <flux:heading size="lg">Email Tracking</flux:heading>
                            </div>

                            <flux:separator variant="subtle" />

                            <div class="-mx-6 overflow-hidden">
                                <flux:table class="[:where(&)]:p-0 [:where(&)]:space-y-0 [&_th]:!px-6 [&_td]:!px-6 w-full">
                                <flux:table.columns>
                                    <flux:table.column>Event</flux:table.column>
                                    <flux:table.column>Template</flux:table.column>
                                    <flux:table.column>Recipients</flux:table.column>
                                    <flux:table.column>Date</flux:table.column>
                                </flux:table.columns>

                                <flux:table.rows>
                                    @foreach ($this->emailTrackingEvents as $event)
                                        <flux:table.row :key="$event->id">
                                            <flux:table.cell>
                                                <flux:badge 
                                                    size="sm" 
                                                    :color="match($event->event_type) {
                                                        'opened' => 'blue',
                                                        'clicked' => 'green',
                                                        'replied' => 'purple',
                                                        'bounced' => 'red',
                                                        'sent' => 'zinc',
                                                        default => 'zinc'
                                                    }"
                                                    inset="top bottom">
                                                    {{ ucfirst($event->event_type) }}
                                                    @if(isset($event->event_count) && $event->event_count > 1)
                                                        <span class="ml-1">x{{ $event->event_count }}</span>
                                                    @endif
                                                </flux:badge>
                                            </flux:table.cell>
                                            <flux:table.cell class="min-w-0">
                                                @if($event->email_template_name)
                                                    <flux:badge size="sm" color="zinc" variant="outline" class="max-w-sm">
                                                        <span class="block truncate" title="{{ $event->email_template_name }}">{{ $event->email_template_name }}</span>
                                                    </flux:badge>
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </flux:table.cell>
                                            <flux:table.cell class="min-w-0">
                                                @if($event->recipient_users && $event->recipient_users->isNotEmpty())
                                                    <div class="text-sm flex items-center min-w-0 gap-1 whitespace-nowrap" title="{{ $event->recipient_users->map(fn($u) => $u->first_name . ' ' . $u->last_name)->implode(', ') }}">
                                                        @php
                                                            $firstRecipient = $event->recipient_users->first();
                                                            $remainingRecipientCount = max(0, $event->recipient_users->count() - 1);
                                                        @endphp

                                                        <span class="inline-block max-w-12 truncate cursor-help" title="{{ $firstRecipient->email }}">
                                                            {{ $firstRecipient->first_name }}
                                                        </span>
                                                        @if($remainingRecipientCount > 0)
                                                            <span class="text-gray-500 shrink-0">+{{ $remainingRecipientCount }}</span>
                                                        @endif
                                                    </div>
                                                @elseif($event->all_recipient_emails && count($event->all_recipient_emails) > 0)
                                                    <div class="text-sm text-gray-500 flex items-center min-w-0 gap-1 whitespace-nowrap" title="{{ implode(', ', $event->all_recipient_emails) }}">
                                                        @php
                                                            $firstRecipientEmail = $event->all_recipient_emails[0] ?? null;
                                                            $remainingRecipientCount = max(0, count($event->all_recipient_emails) - 1);
                                                        @endphp

                                                        @if($firstRecipientEmail)
                                                            <span class="inline-block max-w-20 truncate" title="{{ $firstRecipientEmail }}">{{ $firstRecipientEmail }}</span>
                                                        @endif

                                                        @if($remainingRecipientCount > 0)
                                                            <span class="shrink-0">+{{ $remainingRecipientCount }}</span>
                                                        @endif
                                                    </div>
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </flux:table.cell>
                                            <flux:table.cell>
                                                <time class="whitespace-nowrap" x-data x-datetime="'{{ $event->event_at->toIso8601String() }}'" x-datetime-format="relative"></time>
                                            </flux:table.cell>
                                        </flux:table.row>

                                        {{-- Show thread event sub-rows --}}
                                        @if($event->thread_events && $event->thread_events->count() > 0)
                                            @foreach($event->thread_events as $subEvent)
                                                <flux:table.row :key="'thread-' . $subEvent->id" class="bg-gray-50 dark:bg-gray-800/50 [&_td]:!py-2">
                                                    <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400">
                                                        <flux:badge class="ml-8" 
                                                            size="sm" 
                                                            variant="outline"
                                                            :color="match($subEvent->event_type) {
                                                                'opened' => 'blue',
                                                                'clicked' => 'green',
                                                                'replied' => 'purple',
                                                                'bounced' => 'red',
                                                                'sent' => 'gray',
                                                                default => 'gray'
                                                            }">
                                                            {{ ucfirst($subEvent->event_type) }}
                                                            @if(isset($subEvent->grouped_count) && $subEvent->grouped_count > 1)
                                                                <span class="ml-1">x{{ $subEvent->grouped_count }}</span>
                                                            @endif
                                                        </flux:badge>
                                                    </flux:table.cell>
                                                    <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400 min-w-0">
                                                        {{-- Empty template cell for sub-rows --}}
                                                    </flux:table.cell>
                                                    <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400 min-w-0">
                                                        @if($subEvent->recipient_users && $subEvent->recipient_users->isNotEmpty())
                                                            <div class="text-sm flex items-center min-w-0 gap-1 whitespace-nowrap" title="{{ $subEvent->recipient_users->map(fn($u) => $u->first_name . ' ' . $u->last_name)->implode(', ') }}">
                                                                @php
                                                                    $firstRecipient = $subEvent->recipient_users->first();
                                                                    $remainingRecipientCount = max(0, $subEvent->recipient_users->count() - 1);
                                                                @endphp

                                                                <span class="inline-block max-w-12 truncate cursor-help" title="{{ $firstRecipient->email }}">
                                                                    {{ $firstRecipient->first_name }}
                                                                </span>
                                                                @if($remainingRecipientCount > 0)
                                                                    <span class="text-gray-500 shrink-0">+{{ $remainingRecipientCount }}</span>
                                                                @endif
                                                            </div>
                                                        @elseif($subEvent->all_recipient_emails && count($subEvent->all_recipient_emails) > 0)
                                                            <div class="text-sm text-gray-500 flex items-center min-w-0 gap-1 whitespace-nowrap" title="{{ implode(', ', $subEvent->all_recipient_emails) }}">
                                                                @php
                                                                    $firstRecipientEmail = $subEvent->all_recipient_emails[0] ?? null;
                                                                    $remainingRecipientCount = max(0, count($subEvent->all_recipient_emails) - 1);
                                                                @endphp

                                                                @if($firstRecipientEmail)
                                                                    <span class="inline-block max-w-20 truncate" title="{{ $firstRecipientEmail }}">{{ $firstRecipientEmail }}</span>
                                                                @endif

                                                                @if($remainingRecipientCount > 0)
                                                                    <span class="shrink-0">+{{ $remainingRecipientCount }}</span>
                                                                @endif
                                                            </div>
                                                        @else
                                                            <span class="text-gray-400">-</span>
                                                        @endif
                                                    </flux:table.cell>
                                                    <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400">
                                                        <time class="whitespace-nowrap" x-data x-datetime="'{{ $subEvent->event_at->toIso8601String() }}'" x-datetime-format="relative"></time>
                                                    </flux:table.cell>
                                                </flux:table.row>
                                            @endforeach
                                        @endif
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                            </div>
                        </flux:card>
                        @endif
                    </div>
                @endcan

                {{-- PROJECT LIFESPAN --}}
                <livewire:project-status.status-create :project="$project" lazy />
            </div>

            @can('update', $project)
                <div class="col-span-4 space-y-4 lg:col-span-2">
                    <livewire:expenses.expense-index :project_id="$project->id" :view="'projects.show'" lazy />
                </div>
            @endcan

            @can('update', $project)
                <div class="col-span-4 space-y-4 lg:col-span-2 lg:col-start-3">
                    @if(in_array($this->project->latestStatus->title, ['Active', 'Complete', 'Service Call', 'VIEW ONLY']))
                        {{-- PROJECT PAYMENTS --}}
                        <livewire:payments.payments-index :project="$project" :view="'projects.show'" lazy />

                        {{-- PROJECT FINANCIALS --}}
                        <livewire:projects.project-finances :project="$project" lazy />

                        {{-- PROJECT DISTRIBUTIONS --}}
                        <div x-data="{ loaded: false }" x-intersect.once="$wire.loadDistributions(); loaded = true">
                            @if(!$showDistributions)
                                <flux:card class="space-y-4 animate-pulse">
                                    <div class="h-6 bg-zinc-300 dark:bg-zinc-700 rounded w-1/2"></div>
                                    <div class="h-px bg-zinc-200 dark:bg-zinc-700"></div>
                                    <div class="space-y-2">
                                        @for ($i = 0; $i < 2; $i++)
                                            <div class="h-8 bg-zinc-200 dark:bg-zinc-800 rounded"></div>
                                        @endfor
                                    </div>
                                </flux:card>
                            @elseif($this->project->distributions->isNotEmpty())
                                <flux:card class="space-y-2">
                                {{-- HEADING --}}
                                <div class="flex justify-between">
                                    <flux:heading size="lg" class="mb-0">Project Distributions</flux:heading>
                                </div>

                                <flux:separator variant="subtle" />

                                {{-- DETAILS --}}
                                <x-lists.details_list>
                                    @foreach($this->project->distributions as $distribution)
                                        <x-lists.details_item title="{{$distribution->name}}" detail="{{money($distribution->pivot->amount) . ' | ' . $distribution->pivot->percent . '%'}}" href="{{route('distributions.show', $distribution->id)}}" />
                                    @endforeach
                                </x-lists.details_list>
                            </flux:card>
                            @endif
                        </div>
                    @endif
                </div>
            @endcan
            @cannot('update', $project)
                {{-- CLIENT USER: Payments & Simplified Finances --}}
                <div class="col-span-4 space-y-4 lg:col-span-2 lg:col-start-3">
                    @if(in_array($this->project->latestStatus->title, ['Active', 'Complete', 'Service Call', 'VIEW ONLY']))
                        {{-- CLIENT PAYMENTS (read-only) --}}
                        <livewire:payments.payments-index :project="$project" :view="'estimate.pdf'" lazy />

                        {{-- CLIENT-FRIENDLY PROJECT FINANCES --}}
                        <x-client-finances
                            :project="$this->project"
                            :showReimbursementDownload="true"
                        />
                    @endif
                </div>
            @endcannot
		@endcan
    </div>

    @can('update', $project)
        <livewire:tasks.task-create />
    @endcan
</div>
