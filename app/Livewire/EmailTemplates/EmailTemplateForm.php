<?php

namespace App\Livewire\EmailTemplates;

use App\Models\EmailTemplate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Component;

class EmailTemplateForm extends Component
{
    use AuthorizesRequests;

    public ?EmailTemplate $template = null;
    public $name = '';
    public $subject = '';
    public $body = '';
    public $type = 'estimate';

    public function mount($templateId = null)
    {
        if ($templateId) {
            $this->template = EmailTemplate::findOrFail($templateId);
            $this->name = $this->template->name;
            $this->subject = $this->template->subject;
            $this->body = $this->template->body;
            $this->type = $this->template->type;
        }
    }

    public function save()
    {
        $this->subject = $this->fixMojibake((string) $this->subject);
        $this->body = $this->fixMojibake((string) $this->body);

        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'type' => 'required|string',
        ]);

        $validated['vendor_id'] = auth()->user()->primary_vendor_id;

        if ($this->template) {
            $this->authorize('update', $this->template);
            $this->template->update($validated);
        } else {
            $this->authorize('create', EmailTemplate::class);
            EmailTemplate::create($validated);
        }

        $this->dispatch('template-saved');
        $this->dispatch('close-form');
    }

    protected function fixMojibake(string $text): string
    {
        // Common mojibake sequences caused by UTF-8 bytes being interpreted as Windows-1252.
        // We keep this narrow and explicit so we don't accidentally mutate valid content.
        if (! str_contains($text, 'â') && ! str_contains($text, 'Ã') && ! str_contains($text, 'Â')) {
            return $text;
        }

        return strtr($text, [
            'â€™' => '’',
            'â€œ' => '“',
            'â€�' => '”',
            'â€˜' => '‘',
            'â€”' => '—',
            'â€“' => '–',
            'â€¦' => '…',
            'â€¢' => '•',
            'Â ' => ' ',
            'Â ' => ' ',
        ]);
    }

    public function cancel()
    {
        $this->dispatch('close-form');
    }

    public function insertPlaceholder($placeholder)
    {
        // Dispatch event with placeholder to insert at cursor position
        $this->dispatch('insertPlaceholderAtCursor', placeholder: '{{' . $placeholder . '}}');
    }

    public function render()
    {
        return view('livewire.email-templates.email-template-form');
    }
}

