<div>
    <x-form-modal name="vendor_doc_form_modal" title="Add Vendor Document">
        <form id="vendor_doc_form_modal_form" wire:submit="store" class="space-y-4">
            <flux:input
                wire:model.live="doc_file"
                type="file"
            />
        </form>

        <x-slot name="footer">
            <flux:spacer />
            <flux:button type="submit" form="vendor_doc_form_modal_form" variant="primary">Add Document</flux:button>
        </x-slot>
    </x-form-modal>
</div>
