{{-- Breadcrumb trail for show pages. Items: ['label' => string,
     'href' => string|null, 'icon' => string|null]. A null href renders Flux's
     muted static item — that's how the current page and any policy-gated
     ancestor are expressed (an ungated link to an index the user can't view
     would 403 them). flux:breadcrumbs.item prints EITHER the icon OR the slot,
     never both, so an icon crumb carries no text. --}}
@props(['items' => []])

@if(count($items) > 1)
    <flux:breadcrumbs class="mb-4">
        @foreach($items as $item)
            <flux:breadcrumbs.item
                :href="$item['href'] ?? null"
                :icon="$item['icon'] ?? null"
            >{{ $item['label'] ?? '' }}</flux:breadcrumbs.item>
        @endforeach
    </flux:breadcrumbs>
@endif
