<x-form-modal name="estimate_duplicate_modal" :title="$this->view_text['card_title']">
    <form id="estimate_duplicate_modal_form" wire:submit="{{ $this->view_text['form_submit'] }}" class="space-y-4">
        <flux:radio.group wire:model="cost_mode" label="Cost Mode" variant="segmented">
            <flux:radio value="exact" label="Exact Costs" description="Keep original line item costs" />
            <flux:radio value="current" label="Current Costs" description="Use current global line item costs" />
        </flux:radio.group>

        <flux:radio.group wire:model="include_allowances" label="Allowances" variant="segmented">
            <flux:radio :value="true" label="Include" />
            <flux:radio :value="false" label="Exclude" />
        </flux:radio.group>

        <flux:select label="Client" wire:model.live="client_id" variant="listbox" searchable placeholder="Choose client...">
            @foreach($this->clients as $client)
                <flux:select.option value="{{$client->id}}" wire:key="client-{{$client->id}}">{{ $client->name ?: $client->business_name ?: $client->address ?: ('Client #' . $client->id) }}</flux:select.option>
            @endforeach
        </flux:select>
        <div
            x-show="$wire.client_id"
            x-transition
            >
            <flux:select label="Project" wire:model.live="project_id" variant="listbox" placeholder="Choose project...">
                @foreach($client_projects as $client_project)
                    <flux:select.option value="{{$client_project->id}}" wire:key="project-{{$client_project->id}}">{{ $client_project->project_name ?: $client_project->short_address ?: ('Project #' . $client_project->id) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div
            x-show="$wire.project_id"
            x-transition
            >
            <flux:select label="Target Estimate" wire:model.live="estimate_id" variant="listbox" searchable placeholder="Choose estimate...">
                <flux:select.option value="new" wire:key="new">+ New Estimate</flux:select.option>
                @foreach($this->project_estimates as $estimate)
                    <flux:select.option value="{{$estimate->id}}" wire:key="{{$estimate->id}}">
                        Estimate #{{$estimate->id}}
                        @if($estimate->estimate_sections_count > 0)
                            ({{ $estimate->estimate_sections_count }} sections)
                        @endif
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </form>

    <x-slot name="footer">
        <flux:spacer />
        <flux:button type="submit" form="estimate_duplicate_modal_form" variant="primary">{{ $this->view_text['button_text'] }}</flux:button>
    </x-slot>
</x-form-modal>
