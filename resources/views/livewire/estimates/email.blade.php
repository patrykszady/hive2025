<flux:modal name="estimate_email_modal" class="space-y-4 min-w-96">
    <div class="flex justify-between">
        <flux:heading size="lg">Email Estimate</flux:heading>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="send" class="space-y-4">
        <flux:pillbox wire:model="to" label="To" placeholder="Add email address..." multiple>
            @foreach($to as $email)
                <flux:pillbox.option :value="$email">{{ $this->getUserDisplayName($email) }}</flux:pillbox.option>
            @endforeach
        </flux:pillbox>

        <flux:select wire:model.live="from" label="From" placeholder="Select sender email...">
            @foreach($availableFromEmails as $email)
                <flux:select.option :value="$email">{{ $this->getFromUserDisplayName($email) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input
            wire:model.live.debounce.500ms="subject"
            label="Subject"
            placeholder="Subject"
        />

        <flux:editor wire:model.live="body" />

        <div class="space-y-2">
            <flux:heading size="sm">Attachments</flux:heading>
            <flux:switch wire:model.live="include_estimate_pdf" label="Attach Estimate PDF" />
            <div>
                <flux:switch
                    wire:model.live="include_reimbursements_pdf"
                    label="Attach Project Reimbursements"
                    :disabled="! $hasReimbursements"
                />
                @unless($hasReimbursements)
                    <div class="text-sm italic text-zinc-800 dark:text-white mt-1">The project has no reimbursements recorded.</div>
                @endunless
            </div>
        </div>

        <div class="flex space-x-2">
            <flux:spacer />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="send">
                Send Email
            </flux:button>
        </div>
    </form>
</flux:modal>
