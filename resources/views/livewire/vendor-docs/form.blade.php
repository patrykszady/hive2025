<div>
    <x-form-modal name="vendor_doc_form_modal" title="Add Vendor Document">
        <form id="vendor_doc_form_modal_form" wire:submit="store" class="space-y-4">
            <div
                x-data="{ uploading: false, progress: 0 }"
                x-on:livewire-upload-start="uploading = true; progress = 0"
                x-on:livewire-upload-finish="uploading = false; progress = 100"
                x-on:livewire-upload-error="uploading = false"
                x-on:livewire-upload-cancel="uploading = false"
                x-on:livewire-upload-progress="progress = $event.detail.progress"
                class="space-y-3"
            >
                <flux:field>
                    <flux:label>Document File</flux:label>
                    <flux:input wire:model.live="doc_file" type="file" accept=".pdf,.jpg,.jpeg,.png" />
                    <flux:description>Upload PDF or image files for vendor document processing.</flux:description>
                    <flux:error name="doc_file" />
                </flux:field>

                <div x-show="uploading" x-cloak class="space-y-2">
                    <div class="flex items-center justify-between text-xs text-zinc-600 dark:text-zinc-300">
                        <span>Uploading file...</span>
                        <span x-text="`${progress}%`"></span>
                    </div>
                    <div class="h-2 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                        <div
                            class="h-full bg-zinc-900 dark:bg-zinc-100 transition-all duration-200"
                            :style="`width: ${progress}%`"
                        ></div>
                    </div>
                </div>
            </div>
        </form>

        <x-slot name="footer">
            <flux:spacer />
            <flux:button type="submit" form="vendor_doc_form_modal_form" variant="primary" wire:loading.attr="disabled" wire:target="store,doc_file">
                <span wire:loading.remove wire:target="store,doc_file">Add Document</span>
                <span wire:loading wire:target="store,doc_file" class="inline-flex items-center gap-2">
                    <svg class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                    </svg>
                    Working...
                </span>
            </flux:button>
        </x-slot>
    </x-form-modal>
</div>
