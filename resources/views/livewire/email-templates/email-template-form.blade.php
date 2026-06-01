<form wire:submit="save" class="flex flex-col max-h-[80vh]">
    <div class="flex justify-between">
        <flux:heading size="lg">{{ $template ? 'Edit Template' : 'New Template' }}</flux:heading>
    </div>

    <flux:separator variant="subtle" class="my-4" />

    {{-- Fixed header section --}}
    <div class="space-y-4 shrink-0">
        {{-- Template Name --}}
        <flux:field>
            <flux:label>Template Name</flux:label>
            <flux:input wire:model="name" placeholder="{{ $type === 'contract' ? 'e.g., Standard Contractor Agreement' : 'e.g., Standard Estimate' }}" />
            <flux:error name="name" />
        </flux:field>

        {{-- Type (only for email-family templates) --}}
        @if ($type !== 'contract')
            <flux:field>
                <flux:label>Template Type</flux:label>
                <flux:select wire:model.live="type" variant="listbox">
                    <flux:select.option value="estimate">Estimate</flux:select.option>
                    <flux:select.option value="invoice">Invoice</flux:select.option>
                    <flux:select.option value="lead">Lead Reply</flux:select.option>
                </flux:select>
                <flux:error name="type" />
            </flux:field>
        @endif

        {{-- Placeholder Pills --}}
        <div>
            <flux:description class="mb-2">
                Available placeholders: Click to insert at cursor position
            </flux:description>
            <div class="flex flex-wrap gap-2">
                @foreach($placeholders as $placeholder)
                    <flux:badge 
                        class="cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700" 
                        wire:click="insertPlaceholder('{{ $placeholder }}')"
                    >
                        {{ $placeholder }}
                    </flux:badge>
                @endforeach
            </div>
        </div>

        {{-- Subject Line (only for email templates) --}}
        @if ($type !== 'contract')
            <flux:field>
                <flux:label>Subject Line</flux:label>
                <flux:input wire:model="subject" x-ref="subjectInput" placeholder="e.g., Estimate for @{{client_name}}" />
                <flux:error name="subject" />
            </flux:field>
        @endif

        {{-- Body Label and Page Break Button --}}
        <div class="flex items-center justify-between">
            <flux:label>{{ $type === 'contract' ? 'Contract Body' : 'Email Body' }}</flux:label>
            @if ($type === 'contract')
                <flux:button type="button" wire:click="insertPageBreak" variant="subtle" size="xs" icon="document-minus">
                    Insert Page Break
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Editor with composable toolbar and scrollable content --}}
    <div class="flex-1 min-h-0 my-2 border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
        <flux:editor wire:model="body" x-ref="bodyEditor">
            <flux:editor.toolbar class="shrink-0" />
            <div class="overflow-y-auto max-h-[40vh] min-h-[200px]">
                <flux:editor.content />
            </div>
        </flux:editor>
        <flux:error name="body" />
    </div>

    {{-- Fixed footer section --}}
    <div class="flex space-x-2 shrink-0 pt-4 border-t border-zinc-200 dark:border-zinc-700">
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

$wire.on('insertPageBreakAtCursor', () => {
    // Use a horizontal rule with a data attribute that we'll convert to page-break in PDF
    // The visible <hr> with text gives visual feedback in the editor
    const pageBreakHtml = '<p>---PAGE BREAK---</p>';
    
    // Find the Tiptap editor
    const editorElement = document.querySelector('[x-ref="bodyEditor"]');
    if (editorElement) {
        const proseMirrorEditor = editorElement.querySelector('.tiptap.ProseMirror');
        if (proseMirrorEditor && proseMirrorEditor.editor && proseMirrorEditor.editor.commands) {
            proseMirrorEditor.editor.commands.insertContent(pageBreakHtml);
            proseMirrorEditor.focus();
        }
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
