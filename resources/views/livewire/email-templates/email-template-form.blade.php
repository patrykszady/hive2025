<form wire:submit="save" class="space-y-4">
    <div class="flex justify-between">
        <flux:heading size="lg">{{ $template ? 'Edit Template' : 'New Template' }}</flux:heading>
    </div>

    <flux:separator variant="subtle" />

    <div class="space-y-6">
        {{-- Template Name --}}
        <flux:field>
            <flux:label>Template Name</flux:label>
            <flux:input wire:model="name" placeholder="e.g., Standard Estimate" />
            <flux:error name="name" />
        </flux:field>

        {{-- Placeholder Pills --}}
        <div>
            <flux:description class="mb-2">
                Available placeholders: Click to insert at cursor position
            </flux:description>
            <div class="flex flex-wrap gap-2">
                @foreach(['client_name', 'client_first_names', 'client_last_names', 'project_name', 'project_address_1', 'estimate_total', 'vendor_name'] as $placeholder)
                    <flux:badge 
                        class="cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700" 
                        wire:click="insertPlaceholder('{{$placeholder}}')"
                    >
                        {{ $placeholder }}
                    </flux:badge>
                @endforeach
            </div>
        </div>

        {{-- Subject Line --}}
        <flux:field>
            <flux:label>Subject Line</flux:label>
            <flux:input wire:model="subject" x-ref="subjectInput" placeholder="e.g., Estimate for @{{client_name}}" />
            <flux:error name="subject" />
        </flux:field>

        {{-- Email Body --}}
        <flux:field>
            <flux:label>Email Body</flux:label>
            <flux:editor wire:model="body" x-ref="bodyEditor" class="[&_.tiptap_p]:!my-0 [&_.tiptap_p]:!leading-normal" />
            <flux:error name="body" />
        </flux:field>
    </div>

    <div class="flex space-x-2">
        <flux:spacer />
        <flux:button type="button" wire:click="cancel" variant="ghost">Cancel</flux:button>
        <flux:button type="submit" variant="primary">Save Template</flux:button>
    </div>
</form>

@script
<script>
let lastFocusedElement = null;

// Track which field was last focused
document.addEventListener('focusin', (e) => {
    if (e.target.matches('input[type="text"], .tiptap.ProseMirror')) {
        lastFocusedElement = e.target;
    }
});

$wire.on('insertPlaceholderAtCursor', (event) => {
    const placeholder = event.placeholder;
    
    // Use the last focused element
    const targetElement = lastFocusedElement || document.activeElement;
    
    // Check if it's a regular input (subject field or template name)
    if (targetElement && targetElement.tagName === 'INPUT' && targetElement.type === 'text') {
        // Insert into text input at cursor position
        const start = targetElement.selectionStart ?? 0;
        const end = targetElement.selectionEnd ?? 0;
        const currentValue = targetElement.value ?? '';
        
        const newValue = currentValue.substring(0, start) + placeholder + currentValue.substring(end);
        
        // Update the input value
        targetElement.value = newValue;
        
        // Trigger input event to update Livewire model
        targetElement.dispatchEvent(new Event('input', { bubbles: true }));
        
        // Set cursor position after inserted placeholder
        const newCursorPos = start + placeholder.length;
        targetElement.setSelectionRange(newCursorPos, newCursorPos);
        targetElement.focus();
        
        return;
    }
    
    // Check if target is the Tiptap editor or try to find it
    let proseMirrorEditor = null;
    
    if (targetElement && targetElement.classList && targetElement.classList.contains('ProseMirror')) {
        proseMirrorEditor = targetElement;
    } else {
        // Find the editor
        const editorElement = document.querySelector('[x-ref="bodyEditor"]');
        if (editorElement) {
            proseMirrorEditor = editorElement.querySelector('.tiptap.ProseMirror');
        }
    }
    
    if (proseMirrorEditor) {
        const editorView = proseMirrorEditor.editor;
        
        if (editorView && editorView.commands) {
            editorView.commands.insertContent(placeholder);
        } else {
            proseMirrorEditor.focus();
            document.execCommand('insertText', false, placeholder);
        }
    }
});
</script>
@endscript
