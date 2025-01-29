<?php

namespace App\Livewire\Leads;

use App\Livewire\Forms\LeadForm;
use App\Mail\LeadMessage;
use App\Models\Lead;
use Flux;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

class LeadCreate extends Component
{
    public LeadForm $form;

    public $lead = null;

    public $user = null;

    public $reply = null;

    public $full_name = null;

    public $date = null;

    public $lead_status = null;

    protected $listeners = ['editLead', 'addLead'];

    public $view_text = [
        'card_title' => 'Create Expense',
        'button_text' => 'Create',
        'form_submit' => 'save',
    ];

    public function rules()
    {
        return [
            'lead_status' => 'required',
            'lead.message' => 'required',
            // 12-30-2024 MAKE THIS A CHAT/COMMET/MSG section
            'lead.notes' => 'nullable',
            'lead.address' => 'nullable',
            'lead.origin' => 'required',
            'lead.phone' => 'nullable',
            'lead.email' => 'nullable',
            'reply' => 'nullable',
            'lead.reply_to_email' => 'nullable',
            'full_name' => 'nullable',
            'date' => 'required',
        ];
    }

    public function addLead()
    {
        $this->modal('lead_form_modal')->show();
    }

    public function editLead(Lead $lead)
    {
        $this->lead = $lead;

        $this->lead->message = $this->lead->lead_data->message;
        $this->lead->address = $this->lead->lead_data->address;
        $this->lead->reply_to_email = $this->lead->lead_data->reply_to_email;
        $this->lead->notes = $this->lead->notes;
        $this->lead->origin = $this->lead->origin;
        $this->lead->phone = $this->lead->lead_data->phone;
        $this->lead->email = $this->lead->lead_data->email;
        $this->date = $this->lead->date->format('Y-m-d');
        $this->user = $this->lead->user;
        $this->lead_status = $this->lead->last_status ? $this->lead->last_status->title : null;

        if (! is_null($this->user)) {
            // $this->user->full_name = !is_null($this->user) ? $this->user->full_name : 'Create User';
            $this->full_name = $this->user->full_name;
        } else {
            $this->full_name = $this->lead->lead_data['name'];
        }

        $name = preg_replace('/\s+/', ' ', trim($this->full_name));
        $nameParts = explode(' ', $name);
        $lastName = array_pop($nameParts);
        $firstName = implode(' ', $nameParts);

        $this->reply = 'Hi '.$firstName.',';

        $this->view_text = [
            'card_title' => 'Edit Lead',
            'button_text' => 'Update',
            'form_submit' => 'edit',
        ];

        $this->modal('lead_form_modal')->show();
    }

    public function edit()
    {
        $lead = Lead::findOrFail($this->lead->id);
        $lead->lead_data['address'] = $this->lead->address;
        $lead->lead_data['phone'] = $this->lead->phone;
        $lead->lead_data['email'] = $this->lead->email;
        $lead->notes = $this->lead->notes;
        $lead->save();

        $lead->statuses()->create([
            'title' => $this->lead_status,
            'belongs_to_vendor_id' => $lead->belongs_to_vendor_id,
        ]);

        $this->lead_status = null;
        $this->modal('lead_form_modal')->close();
        $this->dispatch('refreshComponent')->to('leads.leads-index');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Lead Updated.',
            // route / href / wire:click
            text: '',
        );
    }

    public function remove()
    {
        $this->lead->delete();

        $this->lead_status = null;
        $this->modal('lead_form_modal')->close();
        $this->dispatch('refreshComponent')->to('leads.leads-index');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Lead Deleted.',
            // route / href / wire:click
            text: '',
        );
    }

    public function message_reply()
    {
        // dd($this);
        //queue
        //send mail
        // Mail::to('patryk.szady@live.com')->send(new LeadMessage($this->lead, $this->reply));
    }

    public function render()
    {
        return view('livewire.leads.form');
    }
}
