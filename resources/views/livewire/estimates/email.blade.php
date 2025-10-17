<flux:modal name="estimate_email_modal" class="space-y-4 min-w-96">
    <div class="flex justify-between">
        <flux:heading size="lg">Email Estimate</flux:heading>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="send" class="space-y-4">
        <flux:input
            wire:model.live.debounce.500ms="to"
            type="email"
            label="To"
            placeholder="client@example.com"
        />

        <flux:input
            wire:model.live.debounce.500ms="subject"
            label="Subject"
            placeholder="Subject"
        />

        <flux:editor wire:model.live="body" />

        <div class="space-y-2">
            <flux:heading size="sm">Attachments</flux:heading>
            <flux:switch wire:model.live="include_estimate_pdf" label="Attach Estimate PDF" />
            <flux:switch
                wire:model.live="include_reimbursements_pdf"
                label="Attach Project Reimbursements"
                :disabled="! $hasReimbursements"
            />
            @unless($hasReimbursements)
                <flux:description>The project has no reimbursements recorded.</flux:description>
            @endunless
        </div>

        <div class="flex space-x-2">
            <flux:spacer />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="send">
                Send Email
            </flux:button>
        </div>
    </form>
</flux:modal>
