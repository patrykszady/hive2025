<div>
	<div class="grid max-w-xl grid-cols-4 gap-4 lg:max-w-5xl sm:px-6">
		<div class="col-span-4 lg:col-span-2 space-y-4">
            {{-- PROJECT DETAILS --}}
            <x-details.card
                :title="$project->short_address . ' | ' . $project->project_name"
                :subheading="$project->client->name"
                :canEdit="auth()->user()->can('update', $project)"
                :expanded="false"
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

            @if(in_array($project->latestStatus?->status_code, [4, 5, 6, 7, 8, 11]))
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
                    @if(\App\Models\EmailTracking::where('project_id', $project->id)->exists())
                        <livewire:projects.email-tracking-table
                            :project-id="$project->id"
                            lazy
                        />
                    @endif
                @endcan

                {{-- PROJECT LIFESPAN --}}
                <livewire:project-status.status-create :project="$project" lazy />

                @can('update', $project)
                    @if(in_array($this->project->latestStatus->title, ['Active', 'Complete', 'Service Call', 'VIEW ONLY']))
                        {{-- PROJECT PAYMENTS --}}
                        <livewire:payments.payments-index :project="$project" :view="'projects.show'" lazy />

                        {{-- PROJECT FINANCIALS --}}
                        <livewire:projects.project-finances :project="$project" lazy />

                        {{-- PROJECT DISTRIBUTIONS --}}
                        <livewire:projects.project-distributions :project="$project" lazy />
                    @endif
                @endcan

                @cannot('update', $project)
                    @if(in_array($this->project->latestStatus->title, ['Active', 'Complete', 'Service Call', 'VIEW ONLY']))
                        {{-- CLIENT PAYMENTS (read-only) --}}
                        <livewire:payments.payments-index :project="$project" :view="'estimate.pdf'" lazy />

                        {{-- CLIENT-FRIENDLY PROJECT FINANCES --}}
                        <x-client-finances
                            :project="$this->project"
                            :showReimbursementDownload="true"
                        />
                    @endif
                @endcannot
            </div>

            @can('update', $project)
                @if($project->expenses()->exists())
                    <div class="col-span-4 space-y-4 lg:col-span-2">
                        <livewire:expenses.expense-index :project_id="$project->id" :view="'projects.show'" lazy />
                    </div>
                @endif
            @endcan

		@endcan
    </div>

    @can('update', $project)
        <livewire:tasks.task-create />
    @endcan
</div>
