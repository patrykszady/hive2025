<x-page.shell
    :cols="4"
    :breadcrumbs="array_values(array_filter([
        ['label' => 'Projects', 'href' => route('projects.index')],
        $project->client && ! auth()->user()->is_browsing_as_client
            ? ['label' => html_entity_decode((string) $project->client->name, ENT_QUOTES, 'UTF-8'), 'href' => route('clients.show', $project->client)]
            : null,
        ['label' => $project->project_name],
    ]))"
>
    {{-- interleave: below lg the column is display:contents so the global
         order-* sequence weaves both columns into one mobile stack. Empty
         wrappers collapse via the column's hidden-when-empty variant. --}}
    <x-page.column :span="2" :interleave="true">
            {{-- PROJECT DETAILS --}}
            <div class="order-1">
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
                    {{-- Modal host only (teleports) — inside the details slot
                         so the card has no footer and the last row sits flush. --}}
                    @can('update', $project)
                        <livewire:projects.project-create />
                    @endcan
                </x-slot:details>
            </x-details.card>
            </div>

            @can('update', $project)
                <div class="order-2">
                    <livewire:projects.project-vendors :project="$project" />
                </div>
            @endcan

            @can('viewMaterials', $project)
                <div class="order-2">
                    <livewire:projects.project-materials :project="$project" lazy />
                </div>
            @endcan

            @if($this->project->latestStatus?->title !== 'VIEW ONLY')
                <div class="order-3">
                    <livewire:projects.upcoming-tasks :project="$project" lazy />
                </div>
            @endif

            {{-- TIMELAPSE — progress shots with onion-skin capture --}}
            <div class="order-4 lg:order-3">
                <livewire:projects.project-timelapse-card :project="$project" lazy />
            </div>

            @can('viewFinancials', $project)
                @can('update', $project)
                    @if(in_array($this->project->latestStatus?->title, ['Active', 'Complete', 'Service Call', 'VIEW ONLY']) && $project->expenses()->exists())
                        <div class="order-7 lg:order-3">
                            <livewire:expenses.expense-index :project_id="$project->id" :view="'projects.show'" lazy />
                        </div>
                    @endif
                @endcan
            @endcan

            {{-- PROJECT TIMELINE --}}
            {{-- <div class="h-180">
                <livewire:planner.cards-index type="project" :project-id="$project->id" lazy />
            </div> --}}
    </x-page.column>

        @can('viewFinancials', $project)
            <x-page.column :span="2" :interleave="true" class="lg:col-start-3">
                {{-- PROJECT ESTIMATES --}}
                @can('viewAny', App\Models\Estimate::class)
                    <div class="order-4">
                        <livewire:estimates.estimates-index :project="$project" :view="'projects.show'" lazy />
                    </div>
                @endcan

                @can('update', $project)
                    {{-- EMAIL TRACKING --}}
                    @if(\App\Models\EmailTracking::clientFacing()->forProjectAndItsLeads($project->id)->exists())
                        <div class="order-5">
                            <livewire:projects.email-tracking-table
                                :project-id="$project->id"
                                lazy
                            />
                        </div>
                    @endif
                @endcan

                {{-- PROJECT LIFESPAN --}}
                <div class="order-3">
                    <livewire:project-status.status-create :project="$project" lazy />
                </div>

                @can('update', $project)
                    @if(in_array($this->project->latestStatus?->title, ['Active', 'Complete', 'Service Call', 'VIEW ONLY']))
                        {{-- PROJECT PAYMENTS --}}
                        <div class="order-6">
                            <livewire:payments.payments-index :project="$project" :view="'projects.show'" lazy />
                        </div>

                        {{-- PROJECT FINANCIALS --}}
                        <div class="order-8">
                            <livewire:projects.project-finances :project="$project" lazy />
                        </div>

                        {{-- PROJECT DISTRIBUTIONS — skip the component entirely
                             when there are none, so its skeleton never paints a
                             card that the loaded component then removes (same
                             guard as Email Tracking / Materials). --}}
                        @if($this->project->distributions()->exists())
                            <div class="order-9">
                                <livewire:projects.project-distributions :project="$project" lazy />
                            </div>
                        @endif
                    @endif

                    @if(in_array($this->project->latestStatus?->title, ['Prep', 'Scheduled', 'Active', 'Complete', 'Service Call', 'VIEW ONLY']))
                        {{-- LIEN WAIVERS --}}
                        <div class="order-7">
                            <livewire:lien-waivers.index :project="$project" lazy />
                        </div>
                    @endif
                @endcan

                @cannot('update', $project)
                    @if(in_array($this->project->latestStatus?->title, ['Active', 'Complete', 'Service Call', 'VIEW ONLY']))
                        {{-- CLIENT PAYMENTS (read-only) --}}
                        <div class="order-6">
                            <livewire:payments.payments-index :project="$project" :view="'estimate.pdf'" lazy />
                        </div>

                        {{-- CLIENT-FRIENDLY PROJECT FINANCES --}}
                        <div class="order-8">
                            <x-client-finances
                                :project="$this->project"
                                :showReimbursementDownload="true"
                            />
                        </div>
                    @endif
                @endcannot
            </x-page.column>

		@endcan

    <x-slot:offstage>
        @can('update', $project)
            <livewire:tasks.task-create />
        @endcan
    </x-slot:offstage>
</x-page.shell>
