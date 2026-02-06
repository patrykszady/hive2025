<div>
    <flux:card class="mt-4 space-y-2 !px-5 !py-2">
        <div class="flex justify-between items-center min-h-[2.25rem] gap-4">
            <flux:skeleton class="h-6 w-32" />
        </div>
        @if($expanded ?? false)
            <flux:separator variant="subtle" />
            <div class="space-y-3 py-2">
                <div class="flex gap-4">
                    <flux:skeleton class="h-4 w-20" />
                    <flux:skeleton class="h-4 w-24" />
                    <flux:skeleton class="h-4 w-28" />
                </div>
                <div class="flex gap-4">
                    <flux:skeleton class="h-4 w-20" />
                    <flux:skeleton class="h-4 w-24" />
                    <flux:skeleton class="h-4 w-28" />
                </div>
            </div>
        @endif
    </flux:card>
</div>
