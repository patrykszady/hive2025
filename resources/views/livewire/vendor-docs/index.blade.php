<div class="max-w-2xl">
    @livewire('vendor-docs.audit-index')

    @foreach($this->vendors as $vendor)
        <livewire:vendor-docs.vendor-docs-card :$vendor :key="$vendor->id" />
    @endforeach

    <livewire:vendor-docs.vendor-doc-create />
</div>
