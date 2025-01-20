<?php

namespace App\Livewire\Forms;

use App\Models\Expense;
use Livewire\Attributes\Validate;
// use Livewire\Attributes\Rule;
use Livewire\Form;

class PostForm extends Form
{
    #[Validate('required')]
    public $title = '';

    #[Validate('required')]
    public $content = '';

    public Expense $post;

    public function setPost($post)
    {
        $this->post = $post;
        $this->title = $post->id;
        $this->content = $post->amount;
    }

    public function save()
    {
        $this->validate();

        Expense::create([
            'amount' => rand(1, 2000),
            'date' => today(),
            'invoice' => null,
            'note' => null,
            'project_id' => 219,
            'distribution_id' => null,
            'vendor_id' => 8,
            'check_id' => null,
            'paid_by' => null,
            'reimbursment' => null,
            'belongs_to_vendor_id' => 1,
            'created_by_user_id' => 1,
        ]);

        $this->reset(['title', 'content']);
    }

    public function update()
    {
        $this->validate();

        $this->post->update([
            'amount' => $this->content,
            'created_by_user_id' => 1,
        ]);
    }
}
