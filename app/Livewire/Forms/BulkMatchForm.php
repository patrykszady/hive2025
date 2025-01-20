<?php

namespace App\Livewire\Forms;

use App\Models\TransactionBulkMatch;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Form;

class BulkMatchForm extends Form
{
    use AuthorizesRequests;

    public ?TransactionBulkMatch $match;
    // 'distribution_id' => 'required_unless:split,true',
    // 'vendor_amount_group.*.checkbox' => 'nullable',

    #[Validate('required')]
    public $vendor_id = null;

    #[Validate('required_unless:amount_type,ANY|nullable|sometimes|numeric|regex:/^-?\d+(\.\d{1,2})?$/')]
    public $amount = null;

    #[Validate('nullable')]
    public $distribution_id = null;

    #[Validate('nullable')]
    public $amount_type = 'ANY';

    #[Validate('nullable')]
    public $desc = null;

    public function setMatch(TransactionBulkMatch $match)
    {
        $this->match = $match;
        $this->vendor_id = $match->vendor_id;
        $this->amount = $match->amount;
        $this->distribution_id = $match->distribution_id;
        $this->amount_type = $match->options->amount_type;
        $this->desc = $match->options->desc;
    }

    public function options()
    {
        if (! empty($this->component->bulk_splits)) {
            $options['splits'] = [];

            foreach ($this->component->bulk_splits as $index => $split) {
                //2 decimals required for percent %
                $options['splits'][$index]['amount'] = $split['amount_type'] == '%' ? '.'.preg_replace('/\./', '', $split['amount']) : $split['amount'];
                $options['splits'][$index]['amount_type'] = $split['amount_type'];
                $options['splits'][$index]['distribution_id'] = $split['distribution_id'];
            }
        }

        return $options;
    }

    public function update()
    {
        $this->authorize('create', TransactionBulkMatch::class);
        $this->validate();

        $options = $this->options();

        $this->match->update([
            'amount' => $this->amount,
            'vendor_id' => $this->vendor_id,
            'distribution_id' => $this->distribution_id,
            'options' => $options,
        ]);
    }

    public function store()
    {
        $this->authorize('create', TransactionBulkMatch::class);
        $this->validate();

        //$this->options()

        //create new BulkMatch ...
        $bulk_match =
            TransactionBulkMatch::create([
                'amount' => $this->amount,
                'vendor_id' => $this->vendor_id,
                'distribution_id' => $this->distribution_id,
                'options' => [
                    'amount_type' => $this->amount_type,
                    'desc' => $this->desc,
                ],
                'belongs_to_vendor_id' => auth()->user()->vendor->id,
            ]);
    }
}
