<div class="max-w-4xl">
    <flux:card class="space-y-2">
        <div class="flex justify-between items-center">
            <flux:heading size="lg">Templates</flux:heading>
            <flux:button wire:click="createTemplate" icon="plus">New Template</flux:button>
        </div>

        <flux:separator variant="subtle" />

        {{-- Type Tabs (Email vs Contract) --}}
        <flux:tabs wire:model.live="type">
            <flux:tab name="email">Email Templates</flux:tab>
            <flux:tab name="contract">Contract Templates</flux:tab>
        </flux:tabs>

        {{-- Templates Table --}}
        <flux:table :paginate="$this->templates->hasPages() ? $this->templates : null">
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                @if ($type !== 'contract')
                    <flux:table.column>Subject</flux:table.column>
                @endif
                <flux:table.column class="w-20"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->templates as $template)
                    <flux:table.row :key="$template->id">
                        <flux:table.cell variant="strong">
                            <button 
                                wire:click="editTemplate({{ $template->id }})" 
                                class="text-left hover:underline focus:outline-none focus:underline"
                            >
                                {{ $template->name }}
                            </button>
                        </flux:table.cell>
                        
                        @if ($type !== 'contract')
                            <flux:table.cell class="text-sm text-zinc-600 dark:text-zinc-400">
                                {{ $template->subject }}
                            </flux:table.cell>
                        @endif

                        <flux:table.cell>
                            <flux:button 
                                wire:click="editTemplate({{ $template->id }})" 
                                variant="ghost" 
                                size="sm" 
                                icon="pencil"
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="{{ $type !== 'contract' ? 3 : 2 }}" class="text-center text-zinc-500 dark:text-zinc-400 py-8">
                            No templates found. Create your first template to get started.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    {{-- Modal for Create/Edit --}}
    <flux:modal name="template-form" class="min-w-[600px] max-w-4xl w-full">
        @if ($showForm)
            <livewire:email-templates.email-template-form 
                :templateId="$editingTemplateId" 
                :type="$type"
                :key="($editingTemplateId ?? 'new') . '-' . $type"
                @close-form="closeForm"
                @template-saved="closeForm"
            />
        @endif
    </flux:modal>
</div>
