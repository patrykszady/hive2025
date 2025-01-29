<?php

namespace App\Livewire\LineItems;

use App\Models\LineItem;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

class LineItemsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    // public $view;
    public $search = '';
    //public $category = '';

    protected $queryString = [
        'search' => ['except' => ''],
    ];

    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Computed]
    public function line_items()
    {
        return LineItem::orderBy('created_at', 'DESC')
            ->where('name', 'like', '%'.$this->search.'%')
            ->orWhere('desc', 'like', '%'.$this->search.'%')
            ->orWhere('notes', 'like', '%'.$this->search.'%')
            ->paginate(15);
    }

    #[Title('Line Items')]
    public function render()
    {
        $this->authorize('viewAny', LineItem::class);

        return view('livewire.line-items.index');
    }
}
