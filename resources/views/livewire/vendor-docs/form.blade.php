<div>
    <flux:modal name="vendor_doc_form_modal" class="space-y-2">
        <div class="flex justify-between">
            <flux:heading size="lg">Add Vendor Document</flux:heading>
        </div>

        <flux:separator variant="subtle" />

        <form wire:submit="store" class="grid gap-6">
            <flux:input
                wire:model.live="doc_file"
                type="file"
            />

            <div class="flex space-x-2">
                <flux:spacer />

                <flux:button type="submit" variant="primary">Add Document</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
