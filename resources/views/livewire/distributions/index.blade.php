<div>
    <div class="grid max-w-2xl grid-cols-4 gap-4 lg:max-w-5xl sm:px-6">
		<div class="col-span-5 lg:col-span-3 space-y-2">
			{{-- DISTRIBUTION LIST --}}
            <livewire:distributions.distributions-list />

            <livewire:distributions.distribution-projects-table
                type="Without"
                :key="'Without'"
            />

            <livewire:distributions.distribution-projects-table
                type="With"
                :key="'With'"
            />
		</div>
	</div>
    <livewire:distributions.distribution-projects-form />
</div>
