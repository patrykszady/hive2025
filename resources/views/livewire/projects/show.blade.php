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

                        @can('update', $project)
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
</div>
