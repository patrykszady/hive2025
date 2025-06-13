<div>
	<div class="grid max-w-xl grid-cols-4 gap-4 lg:max-w-5xl sm:px-6">
		<div class="col-span-4 lg:col-span-2 space-y-4">
            {{-- PROJECT DETAILS --}}
            <flux:card class="space-y-6">
                {{-- HEADER - Keep outside accordion --}}
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <flux:heading size="lg" class="truncate">{{ $project->address }} {!! $project->project_name !!}</flux:heading>
                        <flux:subheading>{{$project->client->name}}</flux:subheading>
                    </div>

                    @can('update', $project)
                        <div class="flex-shrink-0">
                            <flux:button
                                wire:click="$dispatchTo('projects.project-create', 'editProject', { project: {{$project->id}}})"
                                size="sm"
                                >
                                Edit Project
                            </flux:button>
                        </div>
                    @endcan
                </div>

                {{-- DETAILS LIST wrapped in accordion --}}
                <flux:accordion transition>
                    <flux:accordion.item>
                        <flux:accordion.heading>
                            Project Information
                        </flux:accordion.heading>
                        <flux:accordion.content>
                            <div class="divide-y divide-gray-200">
                                {{-- Project Client --}}
                                <div class="grid grid-cols-3 gap-4 py-2">
                                    <flux:subheading class="text-sm font-medium text-gray-900">Project Client</flux:subheading>
                                    <div class="col-span-2 text-sm text-gray-700 truncate">
                                        <a href="{{route('clients.show', $project->client)}}" class="text-gray-700 hover:text-gray-900 hover:underline">
                                            {{$project->client->name}}
                                        </a>
                                    </div>
                                </div>

                                {{-- Project Name --}}
                                <div class="grid grid-cols-3 gap-4 py-2">
                                    <flux:subheading class="text-sm font-medium text-gray-900">Project Name</flux:subheading>
                                    <div class="col-span-2 text-sm text-gray-700 truncate">{!! $project->project_name !!}</div>
                                </div>

                                {{-- Jobsite Address --}}
                                <div class="grid grid-cols-3 gap-4 py-2">
                                    <flux:subheading class="text-sm font-medium text-gray-900">Jobsite Address</flux:subheading>
                                    <div class="col-span-2 text-sm text-gray-700 truncate">
                                        <a href="{{$project->getAddressMapURI()}}" target="_blank" class="text-gray-700 hover:text-gray-900 hover:underline">
                                            {!!$project->full_address!!}
                                        </a>
                                    </div>
                                </div>

                                @can('update', $project)
                                    {{-- Billing Address --}}
                                    <div class="grid grid-cols-3 gap-4 py-2">
                                        <flux:subheading class="text-sm font-medium text-gray-900">Billing Address</flux:subheading>
                                        <div class="col-span-2 text-sm text-gray-700 truncate">{!!$project->client->full_address!!}</div>
                                    </div>
                                @endcan
                            </div>
                        </flux:accordion.content>
                    </flux:accordion.item>
                </flux:accordion>
            </flux:card>

            {{-- PROJECT TIMELINE --}}
            <div class="h-180">
                <livewire:planner.cards-index type="project" :project-id="$project->id" />
            </div>
		</div>

        @can('update', $project)
            <div class="col-span-4 space-y-4 lg:col-span-2 lg:col-start-3">
                {{-- PROJECT ESTIMATES --}}
                <livewire:estimates.estimates-index :project="$project" :view="'projects.show'" lazy />

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

    {{-- <livewire:projects.project-create /> --}}
</div>
