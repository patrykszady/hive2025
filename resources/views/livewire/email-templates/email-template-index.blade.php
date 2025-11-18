<div>
    <flux:header>
        <flux:heading size="xl">Email Templates</flux:heading>
        
        <flux:spacer />
        
        <flux:button wire:click="createTemplate" icon="plus">New Template</flux:button>
    </flux:header>

    <flux:main class="space-y-6">
        {{-- Template Type Tabs --}}
        <flux:tabs wire:model="type">
            <flux:tab name="estimate">Estimate Templates</flux:tab>
            <flux:tab name="invoice">Invoice Templates</flux:tab>
        </flux:tabs>

        {{-- Templates Table --}}
        <flux:table>
            <flux:table.rows>
                {{-- Header Row --}}
                <flux:table.row>
                    <flux:table.cell class="font-semibold">Name</flux:table.cell>
                    <flux:table.cell class="font-semibold">Subject</flux:table.cell>
                </flux:table.row>

                @foreach ($this->templates as $template)
                    <flux:table.row :key="$template->id">
                        <flux:table.cell class="font-medium">
                            <button 
                                wire:click="editTemplate({{ $template->id }})" 
                                class="text-left hover:underline focus:outline-none focus:underline"
                            >
                                {{ $template->name }}
                            </button>
                        </flux:table.cell>
                        
                        <flux:table.cell class="text-sm text-zinc-600">
                            {{ $template->subject }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        {{ $this->templates->links() }}
    </flux:main>

    {{-- Modal for Create/Edit --}}
    <flux:modal name="template-form" class="space-y-4 min-w-96">
        @if ($showForm)
            <livewire:email-templates.email-template-form 
                :templateId="$editingTemplateId" 
                :key="$editingTemplateId ?? 'new'"
                @close-form="closeForm"
                @template-saved="closeForm"
            />
        @endif
    </flux:modal>
</div>
