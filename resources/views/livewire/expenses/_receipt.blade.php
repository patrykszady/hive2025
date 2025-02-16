<flux:table>
    <flux:columns>
        <flux:column>Desc</flux:column>
        <flux:column>Price</flux:column>
        <flux:column>Qty</flux:column>
        <flux:column>Total</flux:column>
    </flux:columns>

    <flux:rows>
        @foreach($expense->receipts()->latest()->first()->receipt_items->items as $line_item)
            <flux:row>
                <flux:cell colspan="4" class="!pb-0">
                    {{-- {{$line_item->Description}} --}}
                    {{$line_item->Description ? Str::limit($line_item->Description, 65) : ''}}
                </flux:cell>
            </flux:row>
            <flux:row class="!border-none !py-0">
                {{-- 09/28/24 URL TO ITEM --}}
                <flux:cell class="text-right"><i>{{$line_item->ProductCode}}</i></flux:cell>
                <flux:cell>{{money($line_item->Price)}}</flux:cell>
                <flux:cell>{{$line_item->Quantity}}</flux:cell>
                <flux:cell variant="strong">{{money($line_item->TotalPrice)}}</flux:cell>
            </flux:row>
        @endforeach

        <flux:row>
            <flux:cell colspan="3" class="text-right font-medium">Subtotal</flux:cell>
            <flux:cell>{{money($expense->receipts()->latest()->first()->receipt_items->subtotal)}}</flux:cell>
        </flux:row>

        <flux:row>
            <flux:cell colspan="3" class="text-right font-medium">Tax</flux:cell>
            <flux:cell>{{money($expense->receipts()->latest()->first()->receipt_items->total_tax)}}</flux:cell>
        </flux:row>

        <flux:row>
            <flux:cell colspan="3" class="text-right font-medium">Total</flux:cell>
            <flux:cell variant="strong">{{money($expense->receipts()->latest()->first()->receipt_items->total)}}</flux:cell>
        </flux:row>
    </flux:rows>
</flux:table>
