<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Response;
use Livewire\Component;
use Spatie\Browsershot\Browsershot;

class ProjectFinances extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public $finances = [];

    protected $listeners = ['refresh' => 'refreshFinances', 'refreshComponent' => 'refreshFinances'];

    public function mount()
    {
        $this->finances = $this->project->finances;
    }

    public function refreshFinances()
    {
        // Refresh the project model to get fresh data
        $this->project = $this->project->fresh();
        $this->finances = $this->project->finances;
    }

    //Reimbursement print
    //10-16-2024 MOVE TO RECEIPT CONTROLLER
    public function print_reimbursements()
    {
        $this->authorize('view', $this->project);

        $expenses = $this->project->expenses()->where('reimbursment', 'Client')->get();
        $splits = $this->project->expenseSplits()->where('reimbursment', 'Client')->get();

        foreach ($expenses as $expense) {
            $expense->receipt = $expense->receipts()->latest()->first();
            if ($expense->receipt) {
                $expense->receipt_html = $expense->receipt->receipt_html;
                $expense->receipt_filename = $expense->receipt->receipt_filename;
            }
            $expense->business_name = $expense->vendor->business_name ?? null;
            $expense->project_name = $expense->project->name ?? null;
        }

        foreach ($splits as $split) {
            $split->receipt = $split->expense->receipts()->latest()->first();
            if ($split->receipt) {
                $split->receipt_html = $split->receipt->receipt_html;
                $split->receipt_filename = $split->receipt->receipt_filename;
            }
            $split->business_name = optional($split->expense->vendor)->business_name;
            $split->date = optional($split->expense)->date;
            $split->project_name = optional($split->project)->name;
            $split->selectedSplit = $split;

            $expenses->add($split);
        }

        $expenses = $expenses->sortBy('date');

        $title = 'Reimbursements - '.$this->project->id.' - '.$this->project->client->name.' - '.$this->project->project_name;
        $view = view('misc.print_reimbursments', compact(['expenses', 'title']))->render();

        $pdf = Browsershot::html($view)
            ->newHeadless()
            ->addChromiumArguments([
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--single-process',
            ])
            ->showBrowserHeaderAndFooter()
            ->showBackground()
            ->margins(10, 5, 10, 5)
            ->pdf();
        return response()->streamDownload(function () use ($pdf) {
            echo $pdf;
        }, $title.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function render()
    {
        return view('livewire.projects.project-finances');
    }
}
