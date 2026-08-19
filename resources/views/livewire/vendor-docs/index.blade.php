{{-- space-y-4 matches the shared .page-col rhythm: the cards deliberately carry
     no margin of their own (see vendor-docs/card.blade.php), so without it every
     card sat flush against its neighbour as one unbroken block. --}}
<div class="max-w-3xl space-y-4">
    <livewire:vendor-docs.audit-index />

    @foreach($this->vendors as $vendor)
        <livewire:vendor-docs.vendor-docs-card :$vendor :key="$vendor->id" />
    @endforeach

    <livewire:vendor-docs.vendor-doc-create />
</div>
