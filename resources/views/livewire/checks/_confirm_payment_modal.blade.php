{{-- Payment confirmation modal — shared by vendor and timesheet payment pages.
     Expects: $confirm_total (float). "Confirm & Pay" calls the component's save(). --}}
<flux:modal name="confirm-payment" class="md:w-96">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">Confirm Payment</flux:heading>
            <flux:text class="mt-2">Please verify the check amount before proceeding.</flux:text>
        </div>

        <div class="bg-zinc-100 dark:bg-zinc-800 rounded-lg p-4 text-center">
            <flux:text class="text-sm text-zinc-500">Check Total</flux:text>
            <flux:heading size="xl" class="!mt-1">{{ money($confirm_total) }}</flux:heading>
            <flux:text class="mt-1 text-sm italic text-zinc-500">{{ amount_in_words($confirm_total) }}</flux:text>
        </div>

        <div class="flex gap-2">
            <flux:modal.close>
                <flux:button variant="ghost" class="w-full">Cancel</flux:button>
            </flux:modal.close>
            <flux:button wire:click="save" variant="primary" class="w-full">Confirm & Pay</flux:button>
        </div>
    </div>
</flux:modal>
