<x-form-modal name="estimate_email_modal" title="Email Estimate">
    <form id="estimate_email_modal_form" wire:submit="send" class="space-y-4">
        {{-- Recipients --}}
        <div>
            <flux:heading size="sm" class="mb-2">To</flux:heading>

            {{-- Selected recipients as removable pills --}}
            @if(count($to) > 0)
                <div class="flex flex-wrap gap-1.5 mb-2">
                    @foreach($to as $email)
                        <span class="inline-flex items-center gap-1 rounded-md bg-zinc-100 dark:bg-zinc-700 px-2 py-1 text-sm text-zinc-700 dark:text-zinc-200">
                            {{ $this->getUserDisplayName($email) }}
                            <button type="button" wire:click="removeRecipient('{{ $email }}')" class="ml-0.5 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                <flux:icon.x-mark variant="micro" class="size-3.5" />
                            </button>
                        </span>
                    @endforeach
                </div>
            @endif

            {{-- Available contacts --}}
            @if(count($availableContacts) > 0)
                <div class="flex flex-wrap gap-1.5 mb-2">
                    @foreach($availableContacts as $contact)
                        @unless(in_array($contact['email'], $to))
                            <button type="button"
                                wire:click="toggleContact('{{ $contact['email'] }}')"
                                class="inline-flex items-center gap-1 rounded-md border border-dashed border-zinc-300 dark:border-zinc-600 px-2 py-1 text-sm text-zinc-500 dark:text-zinc-400 hover:border-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 transition"
                            >
                                <flux:icon.plus variant="micro" class="size-3.5" />
                                {{ $contact['name'] ?: $contact['email'] }}
                                <span class="text-xs text-zinc-400">({{ $contact['group'] }})</span>
                            </button>
                        @endunless
                    @endforeach
                </div>
            @endif

            {{-- Add custom email --}}
            <div class="flex gap-2">
                <flux:input
                    wire:model="newRecipientEmail"
                    wire:keydown.enter.prevent="addRecipient"
                    type="email"
                    placeholder="Add email address..."
                    class="grow"
                    size="sm"
                />
                <flux:button type="button" wire:click="addRecipient" size="sm" icon="plus">Add</flux:button>
            </div>
            @error('newRecipientEmail')
                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
            @enderror
            @error('to')
                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <flux:select wire:model.live="from" label="From" placeholder="Select sender email...">
            @foreach($availableFromEmails as $companyEmail)
                <flux:select.option :value="$companyEmail->email">{{ $this->getFromUserDisplayName($companyEmail->email) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input.group label="Email Template">
            <flux:select wire:model.live="selectedTemplateId" variant="listbox" placeholder="Select a template...">
                @foreach($availableTemplates as $template)
                    <flux:select.option :value="$template->id">
                        {{ $template->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:button href="{{ route('templates.index') }}" target="_blank" icon="plus">Add</flux:button>
        </flux:input.group>

        <flux:input
            wire:model.live.debounce.500ms="subject"
            label="Subject"
            placeholder="Subject"
        />

        <flux:editor wire:model.live="body" />

        <div class="space-y-2">
            <flux:heading size="sm">Attachments</flux:heading>
            <flux:switch wire:model.live="include_estimate_pdf" label="Attach Estimate PDF" />
            <flux:switch wire:model.live="include_estimate_xlsx" label="Attach Estimate Spreadsheet (.xlsx)" />
            @if($hasReimbursements)
                <flux:switch wire:model.live="include_reimbursements_pdf" label="Attach Project Reimbursements" />
            @endif
        </div>

        <flux:separator variant="subtle" />

        <div class="space-y-3">
            <flux:heading size="sm">Project Status</flux:heading>
            <p class="text-sm text-zinc-600 dark:text-zinc-200">Progress Project to next Status</p>

            @include('livewire.project-status._status_controls')
        </div>
    </form>

    <x-slot name="footer">
        <flux:spacer />
        <flux:button type="submit" form="estimate_email_modal_form" variant="primary" wire:loading.attr="disabled" wire:target="send">
            Send Email
        </flux:button>
    </x-slot>
</x-form-modal>
