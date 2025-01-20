<div>
	<x-page.top
        h1="Distributions"
        p="{!! auth()->user()->vendor->name !!} distributions."
		{{-- right_button_href="{{auth()->user()->can('update', $project) ? route('projects.show', $project->id) : ''}}" --}}
        {{-- right_button_text="Add Distribution" --}}
        >
    </x-page.top>

    <div class="grid max-w-xl grid-cols-4 gap-4 mx-auto lg:max-w-5xl sm:px-6">
		<div class="col-span-4 lg:col-span-2">
			{{-- DISTRIBUTION LIST --}}
            <livewire:distributions.distributions-list />

            {{-- PROJECT DOES NOT HAVE DISTRIBUTIONS --}}
            <x-cards>
                <x-cards.heading>
                    <x-slot name="left">
                        <h1 class="text-lg">Projects <b>Without</b> Distributions</b></h1>
                    </x-slot>
                </x-cards.heading>
                <x-cards.body>
                    <x-lists.ul>
                        @foreach ($projects_doesnt_dis as $project_nohas_dis)
                            <x-lists.search_li
                                line_title="{!! $project_nohas_dis->name !!}"
                                wire:click="$dispatchTo('distributions.distribution-projects-form', 'addDis', { project: {{$project_nohas_dis->id}} })"
                                >
                            </x-lists.search_li>
                        @endforeach
                    </x-lists.ul>
                </x-cards.body>
                <x-cards.footer>
                    {{ $projects_doesnt_dis->links() }}
                </x-cards.footer>
            </x-cards>
		</div>

        <div class="col-span-4 lg:col-span-2">
			{{-- PROJECT HAS DISTRIBUTIONS --}}
			<x-cards>
				<x-cards.heading>
					<x-slot name="left">
						<h1 class="text-lg">Projects <b>With</b> Distributions</h1>
					</x-slot>

					{{-- <x-slot name="right">
						<x-cards.button
							wire:click="$emitTo('bids.bids-form', 'addBids', {{$project}}, {{auth()->user()->vendor}})"
							>
							Edit Bid
						</x-cards.button>
					</x-slot> --}}
				</x-cards.heading>
				<x-cards.body>
					<x-lists.ul>
                        @foreach ($projects_has_dis as $project_has_dis)
                            <x-lists.search_li
                                {{-- :basic=true --}}
                                line_title="{!! $project_has_dis->name !!}"
                                href="{{route('projects.show', $project_has_dis->id)}}"
                                :href_target="'blank'"
                                {{-- :line_data="money($finances['reimbursments'])" --}}
                                >
                            </x-lists.search_li>
                        @endforeach
					</x-lists.ul>
				</x-cards.body>
                <x-cards.footer>
                    {{ $projects_has_dis->links() }}
                </x-cards.footer>
			</x-cards>
		</div>
	</div>

    <livewire:distributions.distribution-projects-form />
</div>
