<div>
	<div class="grid max-w-xl grid-cols-4 gap-4 lg:max-w-5xl sm:px-6">
		<div class="contents lg:col-span-2 lg:flex lg:flex-col lg:gap-4">
            {{-- PROJECT DETAILS --}}
            <div class="col-span-4 order-1">
            <x-details.card
                :title="$project->short_address . ' | ' . $project->project_name"
                :subheading="$project->client->name"
                :canEdit="auth()->user()->can('update', $project)"
                :expanded="true"
                :details_text="false"
                :separator="false"
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
                        :noCloak="true"
                    />

                    {{-- Project Name --}}
                    <x-details.row 
                        title="Project Name" 
                        :content="$project->project_name"
                        :noCloak="true"
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
                            :noCloak="true"
                        />
                    @else
                        {{-- Jobsite Address --}}
                        <x-details.row 
                            title="Jobsite Address" 
                            :content="$project->full_address" 
                            :href="$project->getAddressMapURI()"
                            :copyable="true"
                            :noCloak="true"
                        />

                        @can('viewFinancials', $project)
                            {{-- Billing Address --}}
                            <x-details.row 
                                title="Billing Address" 
                                :content="$project->client->full_address"
                                :href="$project->client->getAddressMapURI()"
                                :copyable="true"
                                :noCloak="true"
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
            </div>

            @can('update', $project)
                <div class="col-span-4 order-2">
                    <livewire:projects.project-vendors :project="$project" />
                </div>
            @endcan

            @can('viewMaterials', $project)
                <div class="col-span-4 order-2">
                    <livewire:projects.project-materials :project="$project" lazy />
                </div>
            @endcan

            @if($this->project->latestStatus?->title !== 'VIEW ONLY')
                <div class="col-span-4 order-3">
                    <livewire:projects.upcoming-tasks :project="$project" lazy />
                </div>
            @endif

            @can('viewFinancials', $project)
                @can('update', $project)
                    @if(in_array($this->project->latestStatus?->title, ['Active', 'Complete', 'Service Call', 'VIEW ONLY']) && $project->expenses()->exists())
                        <div class="col-span-4 order-7 lg:order-3">
                            <livewire:expenses.expense-index :project_id="$project->id" :view="'projects.show'" lazy />
                        </div>
                    @endif
                @endcan
            @endcan

            {{-- PROJECT TIMELINE --}}
            {{-- <div class="h-180">
                <livewire:planner.cards-index type="project" :project-id="$project->id" lazy />
            </div> --}}
		</div>

        @can('viewFinancials', $project)
            <div class="contents lg:col-span-2 lg:flex lg:flex-col lg:gap-4 lg:col-start-3">
                {{-- PROJECT ESTIMATES --}}
                @can('viewAny', App\Models\Estimate::class)
                    <div class="col-span-4 order-4">
                        <livewire:estimates.estimates-index :project="$project" :view="'projects.show'" lazy />
                    </div>
                @endcan

                @can('update', $project)
                    {{-- EMAIL TRACKING --}}
                    @if(\App\Models\EmailTracking::where('project_id', $project->id)->exists())
                        <div class="col-span-4 order-5">
                            <livewire:projects.email-tracking-table
                                :project-id="$project->id"
                                lazy
                            />
                        </div>
                    @endif
                @endcan

                {{-- PROJECT LIFESPAN --}}
                <div class="col-span-4 order-3">
                    <livewire:project-status.status-create :project="$project" lazy />
                </div>

                @can('update', $project)
                    @if(in_array($this->project->latestStatus?->title, ['Active', 'Complete', 'Service Call', 'VIEW ONLY']))
                        {{-- PROJECT PAYMENTS --}}
                        <div class="col-span-4 order-6">
                            <livewire:payments.payments-index :project="$project" :view="'projects.show'" lazy />
                        </div>

                        {{-- PROJECT FINANCIALS --}}
                        <div class="col-span-4 order-8">
                            <livewire:projects.project-finances :project="$project" lazy />
                        </div>

                        {{-- PROJECT DISTRIBUTIONS --}}
                        <div class="col-span-4 order-9">
                            <livewire:projects.project-distributions :project="$project" lazy />
                        </div>
                    @endif

                    @if(in_array($this->project->latestStatus?->title, ['Prep', 'Scheduled', 'Active', 'Complete', 'Service Call', 'VIEW ONLY']))
                        {{-- LIEN WAIVERS --}}
                        <div class="col-span-4 order-7">
                            <livewire:lien-waivers.index :project="$project" lazy />
                        </div>
                    @endif
                @endcan

                @cannot('update', $project)
                    @if(in_array($this->project->latestStatus?->title, ['Active', 'Complete', 'Service Call', 'VIEW ONLY']))
                        {{-- CLIENT PAYMENTS (read-only) --}}
                        <div class="col-span-4 order-6">
                            <livewire:payments.payments-index :project="$project" :view="'estimate.pdf'" lazy />
                        </div>

                        {{-- CLIENT-FRIENDLY PROJECT FINANCES --}}
                        <div class="col-span-4 order-8">
                            <x-client-finances
                                :project="$this->project"
                                :showReimbursementDownload="true"
                            />
                        </div>
                    @endif
                @endcannot
            </div>

		@endcan
    </div>

    @can('update', $project)
        <livewire:tasks.task-create />
    @endcan
</div>
