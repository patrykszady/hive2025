<div>
	<div class="grid max-w-xl grid-cols-4 gap-4 lg:max-w-5xl sm:px-6">
		<div class="col-span-4 lg:col-span-2 space-y-4">
            {{-- PROJECT DETAILS --}}
            <x-details.card
                :title="$project->address . ' | ' . $project->project_name"
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

            {{-- PROJECT TIMELINE --}}
            {{-- <div class="h-180">
                <livewire:planner.cards-index type="project" :project-id="$project->id" />
            </div> --}}
		</div>

        @can('viewFinancials', $project)
            <div class="col-span-4 space-y-4 lg:col-span-2 lg:col-start-3">
                {{-- PROJECT ESTIMATES --}}
                @can('viewAny', App\Models\Estimate::class)
                    <livewire:estimates.estimates-index :project="$project" :view="'projects.show'" lazy />
                @endcan

                {{-- EMAIL TRACKING --}}
                @if($this->emailTrackingEvents->total() > 0)
                    <flux:card class="space-y-2">
                        <div class="flex justify-between items-center">
                            <flux:heading size="lg">Email Tracking</flux:heading>
                        </div>

                        <flux:separator variant="subtle" />

                        <div class="-mx-6 -mb-6 overflow-x-hidden [&_[data-flux-pagination]]:!px-6 [&_[data-flux-pagination]]:!pb-4">
                            <flux:table :paginate="$this->emailTrackingEvents" class="[:where(&)]:p-0 [:where(&)]:space-y-0 [&_th]:!px-4 [&_td]:!px-3 [&_th:first-child]:!ps-6 [&_th:last-child]:!pe-6 [&_td:first-child]:!ps-6 [&_td:last-child]:!pe-6">
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
                                                        'sent' => 'zinc',
                                                        default => 'zinc'
                                                    }"
                                                    inset="top bottom">
                                                    {{ ucfirst($event->event_type) }}
                                                </flux:badge>
                                            </flux:table.cell>
                                            <flux:table.cell>
                                                @if($event->email_template_name)
                                                    <flux:badge size="sm" color="zinc" variant="outline">
                                                        {{ $event->email_template_name }}
                                                    </flux:badge>
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </flux:table.cell>
                                            <flux:table.cell>
                                                @if($event->recipient_users && $event->recipient_users->isNotEmpty())
                                                    <div class="text-sm">
                                                        @foreach($event->recipient_users as $index => $user)
                                                            <span 
                                                                class="cursor-help" 
                                                                title="{{ $user->email }}">
                                                                {{ $user->first_name }}{{ $index < $event->recipient_users->count() - 1 ? ',' : '' }}
                                                            </span>
                                                        @endforeach
                                                    </div>
                                                @elseif($event->all_recipient_emails && count($event->all_recipient_emails) > 0)
                                                    <div class="text-sm text-gray-500">
                                                        {{ implode(', ', $event->all_recipient_emails) }}
                                                    </div>
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </flux:table.cell>
                                            <flux:table.cell>
                                                <span class="cursor-help" title="{{ $event->event_at->format('M j, Y g:i A') }}">
                                                    {{ $event->event_at->diffForHumans() }}
                                                </span>
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
                                                                'sent' => 'gray',
                                                                default => 'gray'
                                                            }">
                                                            {{ ucfirst($subEvent->event_type) }}
                                                        </flux:badge>
                                                    </flux:table.cell>
                                                    <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400">
                                                        {{-- Empty template cell for sub-rows --}}
                                                    </flux:table.cell>
                                                    <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400">
                                                        {{-- Empty recipients cell for sub-rows --}}
                                                    </flux:table.cell>
                                                    <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400">
                                                        <span class="cursor-help" title="{{ $subEvent->event_at->format('M j, Y g:i A') }}">
                                                            {{ $subEvent->event_at->diffForHumans() }}
                                                        </span>
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

                {{-- PROEJCT LIFESPAN --}}
                <livewire:project-status.status-create :project="$project" lazy />
            </div>

            <div class="col-span-4 space-y-4 lg:col-span-2">
                @if(!$project->expenses->isEmpty())
                    <livewire:expenses.expense-index :project_id="$project->id" :view="'projects.show'"/>
                @endif
            </div>

            <div class="col-span-4 space-y-4 lg:col-span-2 lg:col-start-3">
                @if(in_array($this->project->latestStatus->title, ['Active', 'Complete',  'Service Call', 'Service Call Complete', 'VIEW ONLY']))
                    {{-- PROJECT PAYMENTS --}}
                    <livewire:payments.payments-index :project="$project" :view="'projects.show'" />

                    {{-- PROJECT FINANCIALS --}}
                    <livewire:projects.project-finances :project="$project" lazy />

                    {{-- PROJECT DISTRIBUTIONS --}}
                    @if($this->project->distributions->isNotEmpty())
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


                @endif
            </div>
		@endcan
	</div>
</div>
