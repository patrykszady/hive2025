<flux:table>
    <flux:table.columns>
        <flux:table.column>Desc</flux:table.column>
        <flux:table.column>Price</flux:table.column>
        <flux:table.column>Qty</flux:table.column>
        <flux:table.column>Total</flux:table.column>
    </flux:table.columns>

    <flux:table.rows>
        @foreach($expense->receipts()->latest()->first()->receipt_items->items as $line_item)
            <flux:table.row>
                <flux:table.cell colspan="4" class="pb-0!">
                    {{-- {{$line_item->Description}} --}}
                    {{isset($line_item->Description) ? Str::limit($line_item->Description, 65) : ''}}
                </flux:table.cell>
            </flux:table.row>
            <flux:table.row class="border-none! py-0!">
                {{-- 09/28/24 URL TO ITEM --}}
                <flux:table.cell class="text-right"><i>{{$line_item->ProductCode}}</i></flux:table.cell>
                <flux:table.cell>{{money($line_item->Price)}}</flux:table.cell>
                <flux:table.cell>{{$line_item->Quantity}}</flux:table.cell>
                <flux:table.cell variant="strong">{{money($line_item->TotalPrice)}}</flux:table.cell>
            </flux:table.row>
        @endforeach

        <flux:table.row>
            <flux:table.cell colspan="3" class="text-right font-medium">Subtotal</flux:table.cell>
            <flux:table.cell>{{money($expense->receipts()->latest()->first()->receipt_items->subtotal)}}</flux:table.cell>
        </flux:table.row>

        <flux:table.row>
            <flux:table.cell colspan="3" class="text-right font-medium">Tax</flux:table.cell>
            <flux:table.cell>{{money($expense->receipts()->latest()->first()->receipt_items->total_tax)}}</flux:table.cell>
        </flux:table.row>

        <flux:table.row>
            <flux:table.cell colspan="3" class="text-right font-medium">Total</flux:table.cell>
            <flux:table.cell variant="strong">{{money($expense->receipts()->latest()->first()->receipt_items->total)}}</flux:table.cell>
        </flux:table.row>
    </flux:table.rows>
</flux:table>
