<?php

namespace App\Livewire\EmailTemplates;

use App\Models\EmailTemplate;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

class EmailTemplateIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    public $type = 'email';
    public $showForm = false;
    public $editingTemplateId = null;

    #[Title('Templates')]

    public function updatedType()
    {
        $this->resetPage();
    }

    public function createTemplate()
    {
        $this->editingTemplateId = null;
        $this->showForm = true;
        $this->modal('template-form')->show();
    }

    public function editTemplate($templateId)
    {
        $this->editingTemplateId = $templateId;
        $this->showForm = true;
        $this->modal('template-form')->show();
    }

    public function deleteTemplate($templateId)
    {
        $template = EmailTemplate::findOrFail($templateId);
        $this->authorize('delete', $template);
        
        $template->delete();
        $this->dispatch('template-deleted');
    }

    public function closeForm()
    {
        $this->showForm = false;
        $this->editingTemplateId = null;
        $this->modal('template-form')->close();
    }

    #[Computed]
    public function templates()
    {
        // For email type, show estimate and invoice templates
        // For contract type, show contract templates
        if ($this->type === 'email') {
            return EmailTemplate::whereIn('type', ['estimate', 'invoice'])
                ->orderBy('name')
                ->paginate(20);
        }

        return EmailTemplate::where('type', $this->type)
            ->orderBy('name')
            ->paginate(20);
    }

    public function render()
    {
        return view('livewire.email-templates.email-template-index');
    }
}

