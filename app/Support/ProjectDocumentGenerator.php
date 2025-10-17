<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;

class ProjectDocumentGenerator
{
    /**
     * Generate a reimbursements PDF for the given project.
     *
     * @return array{binary:string, filename:string, title:string, path?:string, relative_path?:string}
     */
    public static function generateReimbursements(Project $project, bool $store = false): array
    {
        $project = $project->fresh(['expenses.vendor', 'expenses.receipts', 'expenseSplits.project', 'expenseSplits.expense.vendor', 'expenseSplits.expense.receipts', 'client']);

        $expenses = $project->expenses()->where('reimbursment', 'Client')->get();
        $splits = $project->expenseSplits()->where('reimbursment', 'Client')->get();

        foreach ($expenses as $expense) {
            $receipt = $expense->receipts()->latest()->first();
            if ($receipt) {
                $expense->receipt = $receipt;
                $expense->receipt_html = $receipt->receipt_html;
                $expense->receipt_filename = $receipt->receipt_filename;
            }
            $expense->business_name = optional($expense->vendor)->business_name;
            $expense->project_name = optional($expense->project)->name;
        }

        foreach ($splits as $split) {
            $receipt = optional($split->expense)->receipts()->latest()->first();
            if ($receipt) {
                $split->receipt = $receipt;
                $split->receipt_html = $receipt->receipt_html;
                $split->receipt_filename = $receipt->receipt_filename;
            }

            $split->business_name = optional(optional($split->expense)->vendor)->business_name;
            $split->date = optional($split->expense)->date;
            $split->project_name = optional($split->project)->name;
            $split->selectedSplit = $split;

            $expenses->add($split);
        }

        $expenses = $expenses->sortBy('date');

        $title = 'Reimbursements - ' . $project->id . ' - ' . $project->client->name . ' - ' . $project->project_name;
        $view = view('misc.print_reimbursments', compact('expenses', 'title'))->render();

        $binary = Browsershot::html($view)
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

        $result = [
            'binary' => $binary,
            'filename' => $title . '.pdf',
            'title' => $title,
        ];

        if ($store) {
            $relativePath = 'temp/' . Str::uuid() . '.pdf';
            Storage::disk('local')->put($relativePath, $binary);

            $result['relative_path'] = $relativePath;
            $result['path'] = Storage::disk('local')->path($relativePath);
        }

        return $result;
    }
}
