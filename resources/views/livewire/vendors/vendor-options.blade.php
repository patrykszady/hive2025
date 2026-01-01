<div class="space-y-6">
    <flux:card>
        <div class="flex justify-between items-center">
            <flux:heading size="lg">Options</flux:heading>
        </div>
        <flux:subheading>Configure your company settings</flux:subheading>
        
        <flux:separator class="my-4" />

        <form wire:submit="save" class="space-y-6">
            {{-- Timezone --}}
            <flux:field>
                <flux:label>Timezone</flux:label>
                <flux:description>Used for PDF generation and server-side date formatting</flux:description>
                <flux:select wire:model="timezone" placeholder="Select timezone...">
                    <flux:select.option value="">Use system default</flux:select.option>
                    <flux:select.option value="America/New_York">Eastern Time (America/New_York)</flux:select.option>
                    <flux:select.option value="America/Chicago">Central Time (America/Chicago)</flux:select.option>
                    <flux:select.option value="America/Denver">Mountain Time (America/Denver)</flux:select.option>
                    <flux:select.option value="America/Phoenix">Arizona (America/Phoenix)</flux:select.option>
                    <flux:select.option value="America/Los_Angeles">Pacific Time (America/Los_Angeles)</flux:select.option>
                    <flux:select.option value="America/Anchorage">Alaska (America/Anchorage)</flux:select.option>
                    <flux:select.option value="Pacific/Honolulu">Hawaii (Pacific/Honolulu)</flux:select.option>
                </flux:select>
                <flux:error name="timezone" />
            </flux:field>

            {{-- Short Name --}}
            <flux:field>
                <flux:label>Short Business Name</flux:label>
                <flux:description>A shorter version of your business name (e.g., "GS Construction" instead of "GS Construction & Remodeling")</flux:description>
                <flux:input wire:model="short_name" placeholder="{{ $vendor->business_name }}" />
                <flux:error name="short_name" />
            </flux:field>

            {{-- Business Logo --}}
            <div class="space-y-3">
                <flux:file-upload wire:model="logo" label="Business Logo" :error="$errors->first('logo')">
                    <flux:file-upload.dropzone
                        heading="Drop file or click to browse"
                        text="JPG, PNG, GIF up to 10MB"
                        with-progress
                        inline
                    />
                </flux:file-upload>

                <div class="mt-3 flex flex-col gap-2">
                    @if ($logo)
                        <flux:file-item
                            :heading="$logo->getClientOriginalName()"
                            :image="$logo->temporaryUrl()"
                            :size="$logo->getSize()"
                        >
                            <x-slot name="actions">
                                <flux:file-item.remove
                                    wire:click="removePendingLogo"
                                    aria-label="{{ 'Remove file: ' . $logo->getClientOriginalName() }}"
                                />
                            </x-slot>
                        </flux:file-item>
                    @elseif ($existing_logo)
                        <flux:file-item
                            :heading="basename($existing_logo)"
                            :image="Storage::url($existing_logo)"
                            :size="Storage::disk('public')->exists($existing_logo) ? Storage::disk('public')->size($existing_logo) : 0"
                        >
                            <x-slot name="actions">
                                <flux:file-item.remove
                                    wire:click="removeLogo"
                                    aria-label="{{ 'Remove file: ' . basename($existing_logo) }}"
                                />
                            </x-slot>
                        </flux:file-item>
                    @endif
                </div>
            </div>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">Save Options</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
