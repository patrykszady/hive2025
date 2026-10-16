<?php

namespace App\Livewire\Checks;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\Vendor;
use App\Services\CheckImageUpload;
use Flux\Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

class CheckShow extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public Check $check;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $checkImageFile = null;

    protected $listeners = ['refreshComponent' => '$refresh'];

    /**
     * Column defs for the weekly-timesheet cards — one source of truth for the
     * header and the cells, same contract as TimesheetShow::columnDefs.
     * Widths sum to 100.
     *
     * @return array<int, array{label: string, width: string}>
     */
    public static function timesheetColumnDefs(): array
    {
        return [
            ['label' => 'Amount', 'width' => 'w-[26%] min-w-0'],
            ['label' => 'Hours', 'width' => 'w-[18%] min-w-0'],
            ['label' => 'Project', 'width' => 'w-[56%] min-w-0'],
        ];
    }

    /**
     * Expense rows settled by this check (paid expenses, vendor/user
     * reimbursements): Amount / Date / Vendor / Project.
     *
     * @return array<int, array{label: string, width: string}>
     */
    public static function expenseColumnDefs(): array
    {
        return [
            ['label' => 'Amount', 'width' => 'w-[22%] min-w-0'],
            ['label' => 'Date', 'width' => 'w-[20%] min-w-0'],
            ['label' => 'Vendor', 'width' => 'w-[29%] min-w-0'],
            ['label' => 'Project', 'width' => 'w-[29%] min-w-0'],
        ];
    }

    /**
     * Reimbursements this check paid off for another team member.
     *
     * @return array<int, array{label: string, width: string}>
     */
    public static function reimbursementColumnDefs(): array
    {
        return [
            ['label' => 'Amount', 'width' => 'w-[22%] min-w-0'],
            ['label' => 'Date', 'width' => 'w-[20%] min-w-0'],
            ['label' => 'Team Member', 'width' => 'w-[29%] min-w-0'],
            ['label' => 'Vendor', 'width' => 'w-[29%] min-w-0'],
        ];
    }

    /**
     * Expenses the payee paid back (no date column — grouped by vendor).
     *
     * @return array<int, array{label: string, width: string}>
     */
    public static function paidBackColumnDefs(): array
    {
        return [
            ['label' => 'Amount', 'width' => 'w-[24%] min-w-0'],
            ['label' => 'Vendor', 'width' => 'w-[38%] min-w-0'],
            ['label' => 'Project', 'width' => 'w-[38%] min-w-0'],
        ];
    }

    /**
     * Distribution rows paid by this check.
     *
     * @return array<int, array{label: string, width: string}>
     */
    public static function distributionColumnDefs(): array
    {
        return [
            ['label' => 'Amount', 'width' => 'w-[28%] min-w-0'],
            ['label' => 'Distribution', 'width' => 'w-[72%] min-w-0'],
        ];
    }

    /**
     * Attach a photo of the check, or the bank's check image PDF (its front
     * is cut out), to this check; the analyzer then reads it.
     */
    public function uploadCheckImage(CheckImageUpload $upload): void
    {
        $this->authorize('update', $this->check);
        $context = $this->checkImageContext();
        Log::channel('check_images')->info('Check image upload submitted', $context);

        try {
            $this->validate(
                ['checkImageFile' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:20480']],
                [
                    'checkImageFile.required' => 'Choose a file first; if you did, it is still uploading or the upload failed.',
                    'checkImageFile.mimes' => 'Upload a PDF or a photo (JPG, PNG or WebP). iPhone HEIC photos are not supported.',
                ],
            );
            $image = $upload->attach($this->check, $this->checkImageFile, 'checkImageFile');
        } catch (ValidationException $e) {
            Log::channel('check_images')->warning('Check image upload refused', $context + ['errors' => $e->errors()]);

            throw $e;
        } catch (\Throwable $e) {
            Log::channel('check_images')->error('Check image upload failed', $context + ['error' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()]);
            Flux::toast(text: 'The check image could not be added: '.$e->getMessage(), heading: 'Upload failed', variant: 'danger');

            return;
        }

        Log::channel('check_images')->info('Check image upload saved', $context + ['check_image_id' => $image->id, 'image' => $image->image_filename, 'payee' => $image->payee]);

        $this->reset('checkImageFile');
        $this->modal('upload-check-image')->close();
        Flux::toast(
            text: $image->payee ? 'Payee read from the check: '.$image->payee : 'Saved; the payee could not be read from it.',
            heading: 'Check image added',
            variant: 'success',
        );
    }

    /** The browser finished sending the file to Livewire's temporary upload. */
    public function updatedCheckImageFile(): void
    {
        Log::channel('check_images')->info('Check image file received', $this->checkImageContext());
    }

    /**
     * The browser reported that the file never reached Livewire's temporary
     * upload (a network error, a refused request): logged here, since nothing
     * on the server would otherwise know it was tried.
     */
    public function logCheckImageUploadFailure(string $reason = ''): void
    {
        Log::channel('check_images')->warning('Check image upload did not reach the server', $this->checkImageContext() + [
            'reason' => mb_substr($reason, 0, 300),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 200),
        ]);
    }

    /** @return array<string, mixed> */
    protected function checkImageContext(): array
    {
        $file = $this->checkImageFile;

        return [
            'check_id' => $this->check->id,
            'user_id' => auth()->id(),
            'file' => is_object($file) && method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : null,
            'size' => is_object($file) && method_exists($file, 'getSize') ? $file->getSize() : null,
            'mime' => is_object($file) && method_exists($file, 'getMimeType') ? $file->getMimeType() : null,
        ];
    }

    #[Title('Check')]
    public function render()
    {
        $this->authorize('view', $this->check);
        //paid_by user_id 60 = Robert, find vendor_id on vendor_user table "team members"(include previous/all

        // Get expense IDs from both check_id and many-to-many relationship
        $expenseIdsFromCheckId = Expense::where('check_id', $this->check->id)->pluck('id');
        $expenseIdsFromPivot = $this->check->expensesMany()->pluck('expenses.id');
        $allExpenseIds = $expenseIdsFromCheckId->merge($expenseIdsFromPivot)->unique();

        // EXPENSES
        $vendor_expenses =
            Expense::whereIn('id', $allExpenseIds)
                ->where(function ($query) {
                    $query->whereNull('reimbursment')
                        ->orWhere('reimbursment', 'Client');
                })
                ->whereNull('distribution_id')
                ->whereNull('paid_by')
                ->get();

        $weekly_timesheets = $this->check->user_id
            ? Timesheet::where('check_id', $this->check->id)
                ->where('user_id', $this->check->user_id)
                ->get()
            : collect();

        $employee_weekly_timesheets = $this->check->user_id
            ? Timesheet::where('paid_by', $this->check->user_id)
                ->whereNotNull('paid_by')
                ->where('check_id', $this->check->id)
                ->get()
                ->groupBy(['user_id', 'date'])
            : collect();

        $user_distributions =
            Expense::whereIn('id', $allExpenseIds)
                ->whereNotNull('distribution_id')
                ->whereNull('paid_by')
                ->whereNull('reimbursment')
                ->get();

        // Paid Expenses
        $user_paid_expenses = $this->check->user_id
            ? Expense::whereIn('id', $allExpenseIds)
                ->where('paid_by', $this->check->user_id)
                ->whereNotNull('paid_by')
                ->where(function ($query) {
                    $query->whereNull('reimbursment')
                        ->orWhere('reimbursment', 'Client')
                        ->orWhere(function ($q) {
                            $q->whereRaw('NOT (reimbursment REGEXP "^[0-9]+$")')
                                ->whereRaw('LEFT(reimbursment, 2) != "V:"');
                        });
                })
                ->get()
            : collect();

        $user_paid_by_reimbursements = $this->check->user_id
            ? Expense::whereIn('id', $allExpenseIds)
                ->where('paid_by', $this->check->user_id)
                ->whereNotNull('paid_by')
                ->where('check_id', $this->check->id)
                ->whereNotNull('reimbursment')
                ->where('reimbursment', '!=', 'Client')
                ->orderBy('date', 'DESC')
                ->get()
            : collect();

        $user_reimbursement_expenses = $this->check->user_id
            ? Expense::whereIn('id', $allExpenseIds)
                ->where('reimbursment', (string) $this->check->user_id)
                ->whereNull('paid_by')
                ->orderBy('date', 'DESC')
                ->get()
                ->keyBy('id')
            : collect();

        // Vendor reimbursements settled by this check — expenses the company
        // paid on the payee vendor's behalf (reimbursment = 'V:{vendor_id}'),
        // deducted from the check total by Check::recalculateAmount().
        $vendor_reimbursement_expenses = $this->check->vendor_id
            ? Expense::whereIn('id', $allExpenseIds)
                ->where('reimbursment', 'V:'.$this->check->vendor_id)
                ->orderBy('date', 'DESC')
                ->get()
            : collect();

        // Scanned check images cropped from bank statements
        $check_images = $this->check->checkImages()->orderBy('check_date')->get();

        return view('livewire.checks.show', [
            'check_images' => $check_images,
            'vendor_expenses' => $vendor_expenses,
            'vendor_reimbursement_expenses' => $vendor_reimbursement_expenses,
            'user_paid_expenses' => $user_paid_expenses,
            'user_reimbursement_expenses' => $user_reimbursement_expenses,
            'user_paid_by_reimbursements' => $user_paid_by_reimbursements,
            'weekly_timesheets' => $weekly_timesheets,
            'employee_weekly_timesheets' => $employee_weekly_timesheets,
            'user_distributions' => $user_distributions,
        ]);
    }
}
