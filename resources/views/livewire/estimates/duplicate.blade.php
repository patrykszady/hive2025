<flux:modal name="estimate_duplicate_modal">
    <div class="flex justify-between space-y-2">
        <flux:heading size="lg">Duplicate This Estimate</flux:heading>
    </div>

    <flux:separator variant="subtle" class="mb-2" />

    <form wire:submit="save" class="space-y-2">
        <flux:select label="Client" wire:model.live="client_id" variant="listbox" searchable placeholder="Choose client...">
            @foreach($this->clients as $client)
                <flux:select.option value="{{$client->id}}" wire:key="{{$client->id}}">{{$client->name}}</flux:select.option>
            @endforeach
        </flux:select>
        <div
            x-show="$wire.client_id"
            x-transition
            >
            <flux:select label="Project" wire:model.live="project_id" variant="listbox" searchable placeholder="Choose project...">
                @foreach($client_projects as $client_project)
                    <flux:select.option value="{{$client_project->id}}" wire:key="{{$client_project->id}}">{{$client_project->project_name}}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        {{-- FOOTER --}}
        <div
            x-show="$wire.project_id"
            x-transition
            class="flex space-x-2 sticky bottom-0"
            >
            <flux:spacer />

            <flux:button type="submit" variant="primary">Duplicate</flux:button>
        </div>
    </form>
</flux:modal>
