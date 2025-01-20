@props([
    'split_true' => false
])

@if(isset($item->valueObject))
    @php
        $line_details = [
        1 => [
            //$item->valueObject->Quantity->valueNumber . ' @ ' . $item->valueObject->Price->valueNumber . ' = ' . money($item->valueObject->TotalPrice->valueNumber)
            'text' => $item->quantity . ' @ ' . money($item->price_each) . ' = ' . money($item->price_total),
                // isset($item->valueObject->Quantity) ? $item->valueObject->Quantity->valueNumber : ''
                // . ' @ ' .
                // isset($item->valueObject->Price) ? $item->valueObject->Price->valueNumber : ''
                // . ' = ' .
                // isset($item->valueObject->TotalPrice) ? money($item->valueObject->TotalPrice->valueNumber) : '',
            'icon' => 'M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z'
            ],
        2 => [
            //$item->valueObject->Quantity->valueNumber . ' @ ' . $item->valueObject->Price->valueNumber . ' = ' . money($item->valueObject->TotalPrice->valueNumber)
            'text' => $item->product_code,
            'icon' => NULL,
                // isset($item->valueObject->Quantity) ? $item->valueObject->Quantity->valueNumber : ''
                // . ' @ ' .
                // isset($item->valueObject->Price) ? $item->valueObject->Price->valueNumber : ''
                // . ' = ' .
                // isset($item->valueObject->TotalPrice) ? money($item->valueObject->TotalPrice->valueNumber) : '',
            // 'icon' => 'M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z'
            ],
        ];
    @endphp

    <x-lists.search_li
        {{-- :basic=true --}}
        :left_line=$split_true
        :line_title="$item->desc"
        :line_details="$line_details"
        :class="'text-xl text-indigo-800'"
        >

    </x-lists.search_li>
@endif
