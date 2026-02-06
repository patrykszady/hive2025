<div>
@if($this->project->distributions->isNotEmpty())
    <x-island-card heading="Project Distributions" :separator="true" wire:transition>

        <x-lists.details_list>
            @foreach($this->project->distributions as $distribution)
                <x-lists.details_item
                    title="{{ $distribution->name }}"
                    detail="{{ money($distribution->pivot->amount) . ' | ' . $distribution->pivot->percent . '%' }}"
                    href="{{ route('distributions.show', $distribution->id) }}"
                />
            @endforeach
        </x-lists.details_list>
    </x-island-card>
@endif
</div>
