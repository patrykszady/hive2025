<x-form-modal name="estimate_email_modal" title="Email Estimate">
    <form id="estimate_email_modal_form" wire:submit="send" class="space-y-4">
        <flux:pillbox wire:model="to" label="To" placeholder="Add email address..." multiple>
            @foreach($to as $email)
                <flux:pillbox.option :value="$email">{{ $this->getUserDisplayName($email) }}</flux:pillbox.option>
            @endforeach
        </flux:pillbox>

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

            <flux:button href="{{ route('email_templates.index') }}" target="_blank" icon="plus">Add</flux:button>
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
