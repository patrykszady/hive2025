<div>
	<div class="grid max-w-xl grid-cols-4 gap-4 lg:max-w-5xl sm:px-6">
		{{--  lg:h-32 lg:sticky lg:top-5 --}}
		<div class="col-span-4 lg:col-span-2 space-y-4">
			{{-- PROJECT DETAILS --}}
            <x-lists.details_card>
                {{-- HEADING --}}
                <x-slot:heading>
                    <div>
                        <flux:heading size="lg" class="mb-0">Project Details</flux:heading>
                    </div>

                    @can('update', $project)
                        <flux:button
                            wire:click="$dispatchTo('projects.project-create', 'editProject', { project: {{$project->id}}})"
                            size="sm"
                            >
                            Edit Project
                        </flux:button>
                    @endcan
                </x-slot>

                {{-- DETAILS --}}
                <x-lists.details_list>
                    <x-lists.details_item title="Project Client" detail="{{$project->client->name}}" href="{{route('clients.show', $project->client)}}" />
                    <x-lists.details_item title="Project Name" detail="{!! $project->project_name !!}" />
                    <x-lists.details_item title="Jobsite Address" detail="{!!$project->full_address!!}" href="{{$project->getAddressMapURI()}}" target="_blank" />

                    @can('update', $project)
                        <x-lists.details_item title="Billing Address" detail="{!!$project->client->full_address!!}" />
                        {{-- @if($project->belongs_to_vendor_id == auth()->user()->vendor->id)
                            <x-lists.search_li
                                :basic=true
                                :line_title="'Invite Contractors'"
                                :line_data="'Choose Vendors'"
                                :button_wire="TRUE"
                                wire:click="$dispatchTo('projects.project-vendors', 'addVendors')"
                                >
                            </x-lists.search_li>

                            <livewire:projects.project-vendors :project="$project"/>
                        @endif --}}
                    @endcan
                </x-lists.details_list>
            </x-lists.details_card>
		</div>

        @can('update', $project)
            <div class="col-span-4 space-y-4 lg:col-span-2 lg:col-start-3">
                {{-- PROJECT ESTIMATES --}}
                <livewire:estimates.estimates-index :project="$project" :view="'projects.show'" lazy />

                {{-- PROEJCT LIFESPAN --}}
                <livewire:project-status.status-create :project="$project" lazy />
            </div>
        @endcan

        @can('update', $project)
            {{-- @if($project->tasks->count() != 0)
                <div class="col-span-4 space-y-4">
                    <livewire:tasks.planner :single_project_id="$project->id" />
                </div>
            @endif --}}

            <div class="col-span-4 space-y-4 lg:col-span-2">
                @if(!$project->expenses->isEmpty())
                    <livewire:expenses.expense-index :project="$project->id" :view="'projects.show'"/>
                @endif
            </div>
        @endcan

		@can('update', $project)
            <div class="col-span-4 space-y-4 lg:col-span-2 lg:col-start-3">
                @if(in_array($this->project->last_status->title, ['Active', 'Complete',  'Service Call', 'Service Call Complete', 'VIEW ONLY']))
                    {{-- PROJECT FINANCIALS --}}
                    <livewire:projects.project-finances :project="$project" lazy />

                    {{-- PROJECT DISTRIBUTIONS --}}
                    @if(!$this->project->distributions->isEmpty())
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

                    {{-- PROJECT PAYMENTS --}}
                    <livewire:payments.payments-index :project="$project" :view="'projects.show'" />
                @endif
            </div>
		@endcan
	</div>

    <livewire:projects.project-create />
</div>
