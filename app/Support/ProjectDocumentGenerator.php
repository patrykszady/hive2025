<?php

namespace App\Support;

use App\Models\Project;
use App\Scopes\ExpenseScope;
use App\Scopes\ExpenseSplitsScope;
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

        $expenses = $project->expenses()
            ->withoutGlobalScope(ExpenseScope::class)
            ->where('reimbursment', 'Client')
            ->with(['vendor', 'receipts', 'project'])
            ->get();
        $splits = $project->expenseSplits()
            ->withoutGlobalScope(ExpenseSplitsScope::class)
            ->where('reimbursment', 'Client')
            ->with([
                'project',
                'expense' => fn ($query) => $query
                    ->withoutGlobalScope(ExpenseScope::class)
                    ->with(['vendor', 'receipts', 'project']),
            ])
            ->get();

        foreach ($expenses as $expense) {
            // Newest receipt, but a notes-only supplement scan never outranks
            // the primary copy that actually carries the line items.
            $receipt = $expense->receipts
                ->sortByDesc('created_at')
                ->sortBy(fn ($r) => $r->isSupplement() ? 1 : 0)
                ->first();
            if ($receipt) {
                $receipt->setRelation('expense', $expense);
                $expense->receipt = $receipt;
                $expense->receipt_html = $receipt->receipt_html;
                $expense->receipt_filename = $receipt->receipt_filename;
            }
            $expense->business_name = optional($expense->vendor)->business_name;
            $expense->project_name = optional($expense->project)->name;
        }

        foreach ($splits as $split) {
            $receipt = $split->expense
                ? $split->expense->receipts
                    ->sortByDesc('created_at')
                    ->sortBy(fn ($r) => $r->isSupplement() ? 1 : 0)
                    ->first()
                : null;
            if ($receipt) {
                if ($split->expense) {
                    $receipt->setRelation('expense', $split->expense);
                }
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

        // PDFs should use the vendor's timezone, not browser timezone
        $timezone = vendor_timezone();
        $title = 'Reimbursements - ' . $project->id . ' - ' . $project->client->name . ' - ' . $project->project_name;
        $view = view('misc.print_reimbursments', compact('expenses', 'title'))->render();

        $browsershot = Browsershot::html($view)
            ->newHeadless()
            ->addChromiumArguments([
                'no-sandbox',
                'disable-setuid-sandbox',
                'disable-dev-shm-usage',
                'disable-gpu',
                'single-process',
            ])
            ->timeout(60)
            ->showBrowserHeaderAndFooter()
            ->showBackground()
            ->headerHtml('<div style="font-size: 10px; width: 100%; padding: 0; margin: 0 5mm 0 10mm; display: flex; justify-content: space-between;"><span>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span><span>' . now()->setTimezone($timezone)->format('m/d/Y g:i A') . '</span></div>')
            ->footerHtml('<div style="font-size: 10px; text-align: right; width: 100%; padding: 0; margin: 0 5mm 0 10mm;"><span class="pageNumber"></span> / <span class="totalPages"></span></div>')
            ->margins(10, 5, 10, 5);

        if ($chromePath = env('CHROME_PATH')) {
            $browsershot->setChromePath($chromePath);
        }

        $binary = $browsershot->pdf();

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
