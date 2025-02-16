<?php

namespace App\Livewire\BulkMatch;

use App\Livewire\Forms\BulkMatchForm;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\TransactionBulkMatch;
use App\Models\Vendor;
use Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

class BulkMatchCreate extends Component
{
    public BulkMatchForm $form;

    public $new_vendor = null;

    public $split = false;

    public $splits_count = 0;

    public $bulk_splits = [];

    public $view_text = [
        'card_title' => 'Add New Automatic Bulk Match',
        'button_text' => 'Create Bulk Match',
        'form_submit' => 'save',
    ];

    protected $listeners = ['newMatch', 'updateMatch'];

    public function rules()
    {
        return [
            'split' => 'nullable',
        ];
    }

    public function updated($field, $value)
    {
        // if SPLIT true vs false
        if($field === 'split'){
            if($this->split === true){
                $this->bulkSplits();
                $this->split = true;
                $this->form->distribution_id = NULL;
            }else{
                $this->split = false;
                $this->bulk_splits = [];
            }
        }

        // dd($field, $value);

        // if($field == 'form.amount_type' && $value == 'NEW'){
        //     $this->form->amount = NULL;
        //     $this->form->amount_type = 'ANY';
        // }
        // dd($field, $value);
        // if($field == 'form.amount_type' && $value == TRUE){
        //     $this->form->amount = NULL;
        //     $this->form->amount_type = NULL;
        // }elseif($field == 'form.amount_type' && $value == FALSE){
        //     $this->form->amount_type = 'ANY';
        // }

        // if ($field == 'form.vendor_id' && $value != null && ! isset($this->form->match)) {
        //     $this->new_vendor = Vendor::findOrFail($value);
        //     $this->new_vendor->vendor_transactions =
        //         $this->new_vendor->transactions()
        //             ->whereDoesntHave('expense')
        //             ->whereDoesntHave('check')
        //             ->orderBy('amount', 'DESC')
        //             ->get()
        //             ->groupBy('amount')
        //             ->values()
        //         //converts to array?
        //             ->toBase();

        //     $this->new_vendor->vendor_expenses =
        //         $this->new_vendor->expenses()
        //             ->whereDoesntHave('splits')
        //             ->where('project_id', '0')
        //             ->whereNull('distribution_id')
        //             ->orderBy('amount', 'DESC')
        //             ->get()
        //             ->groupBy('amount')
        //             ->toBase();
        // } elseif ($field == 'form.vendor_id' && $value == null && ! isset($this->form->match)) {
        //     $this->new_vendor = null;
        // }


        // $this->validate();
        $this->validateOnly($field);
    }

    #[Computed]
    public function new_vendors()
    {
        $transactions =
            Transaction::whereHas('vendor')->whereDoesntHave('expense')->whereNull('check_number')->whereNotNull('posted_date')->where('posted_date', '<', today()->subDays(3)->format('Y-m-d'))
                ->get()->groupBy('vendor_id');

        $expenses_no_project =
            Expense::whereHas('vendor')->whereDoesntHave('splits')->where('project_id', '0')->whereNull('distribution_id')
                ->get()->groupBy('vendor_id');

        return Vendor::whereIn('id', $transactions->keys())->orWhereIn('id', $expenses_no_project->keys())->where('business_type', 'Retail')->orderBy('business_name')->get();
        // $this->existing_vendors = Vendor::whereIn('id', $vendors)->get();
    }

    #[Computed]
    public function vendors()
    {
        return Vendor::where('business_type', 'Retail')->get();
    }

    #[Computed]
    public function distributions()
    {
        return Distribution::all();
    }

    public function bulkSplits()
    {
        $this->bulk_splits = collect();
        $this->bulk_splits->push(['amount' => null, 'amount_type' => '$', 'distribution_id' => null]);
        $this->bulk_splits->push(['amount' => null, 'amount_type' => '$', 'distribution_id' => null]);
        $this->splits_count = 2;
    }

    public function addSplit()
    {
        $this->splits_count = $this->splits_count ++;
        $this->bulk_splits->push(['amount' => null, 'amount_type' => '$', 'distribution_id' => null]);
    }

    public function removeSplit($index)
    {
        $this->splits_count = $this->splits_count --;
        unset($this->bulk_splits[$index]);
    }

    public function newMatch()
    {
        $this->new_vendor = null;
        $this->split = false;
        $this->splits_count = 0;
        $this->bulk_splits = [];
        // $this->reset();
        $this->form->reset();

        $this->view_text = [
            'card_title' => 'New Automatic Bulk Match',
            'button_text' => 'Create Bulk Match',
            'form_submit' => 'save',
        ];

        $this->modal('bulk_match_form_modal')->show();
    }

    public function updateMatch(TransactionBulkMatch $match)
    {
        $this->new_vendor = null;
        $this->split = false;
        $this->splits_count = 0;
        $this->bulk_splits = [];
        $this->form->reset();
        $this->form->setMatch($match);

        if (isset($match->options['splits'])) {
            $this->split = true;
            $this->splits_count = count($match->options['splits']);
            $this->bulk_splits = $match->options['splits'];
        }

        $this->view_text = [
            'card_title' => 'Edit '.$match->vendor->name.' Bulk Match',
            'button_text' => 'Edit Bulk Match',
            'form_submit' => 'edit',
        ];

        $this->modal('bulk_match_form_modal')->show();
    }

    public function remove()
    {
        $this->form->match->delete();

        $this->dispatch('refreshComponent')->to('bulk-match.bulk-match-index');
        $this->modal('bulk_match_form_modal')->close();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Match Removed',
            // route / href / wire:click
            text: '',
        );
    }

    public function edit()
    {
        dd('in edit');
        $this->form->update();
        //refresh main component of transactions/bulk_match
        $this->dispatch('refreshComponent')->to('bulk-match.bulk-match-index');
        $this->modal('bulk_match_form_modal')->close();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Match Updated',
            // route / href / wire:click
            text: '',
        );
    }

    public function save()
    {
        $this->form->store();

        $this->dispatch('refreshComponent')->to('bulk-match.bulk-match-index');
        $this->modal('bulk_match_form_modal')->close();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Match Created',
            // route / href / wire:click
            text: '',
        );
    }

    public function render()
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        return view('livewire.bulk-match.form');
    }
}
