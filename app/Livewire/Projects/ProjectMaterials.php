<?php

namespace App\Livewire\Projects;

use Carbon\Carbon;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ReceiptLineItemDesc;
use App\Models\Vendor;
use App\Scopes\ExpenseScope;
use App\Support\MaterialOrderStatus;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class ProjectMaterials extends Component
{
    use WithFileUploads;

    public Project $project;

    public $file;

    public bool $showUploadModal = false;

    public $belongs_to_vendor_id = null;

    public bool $showItemModal = false;

    public ?array $selectedItem = null;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function rules(): array
    {
        return [
            'file' => 'required|mimes:pdf,jpg,jpeg,png|max:20480',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload.',
            'file.mimes' => 'File must be a PDF, JPG, or PNG.',
            'file.max' => 'File must be less than 20MB.',
        ];
    }

    public function openUploadModal(): void
    {
        $this->reset('file');
        $this->belongs_to_vendor_id = null;
        $this->resetValidation();
        $this->showUploadModal = true;
    }

    #[Computed]
    public function vendors()
    {
        return Vendor::orderBy('business_name')->get(['id', 'business_name']);
    }

    public function selectItem(int $expenseId, int $itemIndex): void
    {
        $expense = Expense::withoutGlobalScope(ExpenseScope::class)
            ->where('project_id', $this->project->id)
            ->with(['vendor', 'orderedReceipts'])
            ->find($expenseId);

        if (! $expense) {
            return;
        }

        $receipt = $expense->orderedReceipts->first(fn ($r) => $r->is_material_order);
        $items = $receipt?->receipt_items['items'] ?? [];
        $item = $items[$itemIndex] ?? null;

        if (! $item) {
            return;
        }

        // Enrich with receipt_line_item_descs
        $desc = ReceiptLineItemDesc::where('expense_receipt_id', $receipt->id)
            ->where('item_index', $itemIndex)
            ->first();

        if ($desc) {
            if (empty($item['image_url']) && ! empty($desc->product_image_url)) {
                $item['image_url'] = $desc->product_image_url;
            }

            if (empty($item['product_url']) && ! empty($desc->product_url)) {
                $item['product_url'] = $desc->product_url;
            }

            if (empty($item['Area']) && ! empty($desc->area)) {
                $item['Area'] = $desc->area;
            }
        }

        $item['_vendor_name'] = $expense->vendor?->business_name;
        $item['_expense_date'] = $expense->date?->format('M j, Y');

        // Fetch activity log changes for this specific item
        $item['_history'] = Activity::where('log_name', 'materials')
            ->where('subject_type', 'App\\Models\\ExpenseReceipts')
            ->where('subject_id', $receipt->id)
            ->latest()
            ->get()
            ->flatMap(function ($log) use ($itemIndex) {
                $tz = auth()->user()?->vendor?->timezone ?? config('app.timezone');
                return collect($log->properties['items'] ?? [])
                    ->filter(fn ($change) => ($change['item_index'] ?? null) === $itemIndex)
                    ->map(fn ($change) => array_merge($change, ['date' => $log->created_at->timezone($tz)->format('n/j/y g:iA')]));
            })
            ->values()
            ->toArray();

        $this->selectedItem = $item;
        $this->showItemModal = true;
    }

    public function uploadReceipt(): void
    {
        $this->validate();

        $docType = $this->file->getClientOriginalExtension();
        $ocrFilename = date('Y-m-d-H-i-s') . '-' . rand(10, 99) . '.' . $docType;
        $ocrPath = '_temp_ocr/' . $ocrFilename;

        Storage::disk('files')->put($ocrPath, file_get_contents($this->file->getRealPath()));

        $analyzerId = config('services.azure_cu.analyzer_id_material_order');

        $ocrData = app(\App\Http\Controllers\ReceiptController::class)
            ->extractReceipt($ocrPath, 'material_order', null, null, null, $analyzerId);

        if (isset($ocrData['error']) && $ocrData['error'] === true) {
            Storage::disk('files')->delete($ocrPath);
            $this->addError('file', 'Unable to process receipt. Please check the file and try again.');

            return;
        }

        $fields = $ocrData['fields'] ?? [];

        // Match vendor from OCR merchant name
        $vendorId = null;
        $merchantName = $fields['merchant_name'] ?? null;

        if ($merchantName) {
            $vendors = Vendor::orderBy('business_name')->get(['id', 'business_name']);
            $matched = app(\App\Http\Controllers\CompanyEmailController::class)
                ->fuzzyMatchVendor($merchantName, $vendors, 70.0);

            if ($matched) {
                $vendorId = $matched->id;
            }
        }

        $hubVendorId = auth()->user()->vendor->id;
        $ownerVendorId = $this->belongs_to_vendor_id ?: $hubVendorId;

        $expense = Expense::create([
            'amount' => $fields['total'] ?? 0,
            'date' => $fields['transaction_date'] ?? now()->format('Y-m-d'),
            'invoice' => $fields['invoice_number'] ?? null,
            'vendor_id' => $vendorId,
            'project_id' => $this->project->id,
            'belongs_to_vendor_id' => $ownerVendorId,
            'created_by_user_id' => auth()->user()->id,
        ]);

        // Ensure the matched vendor is in the owner vendor's vendor list
        if ($vendorId && $ownerVendorId !== $hubVendorId) {
            $ownerVendor = Vendor::withoutGlobalScopes()->find($ownerVendorId);
            if ($ownerVendor && ! $ownerVendor->vendors()->where('vendor_id', $vendorId)->exists()) {
                $ownerVendor->vendors()->attach($vendorId);
            }
        }

        // Attach project to the owner vendor with Invited status
        if ($ownerVendorId !== $hubVendorId) {
            if (! $this->project->vendors()->withoutGlobalScopes()->where('vendor_id', $ownerVendorId)->exists()) {
                $this->project->vendors()->withoutGlobalScopes()->attach($ownerVendorId, ['client_id' => $this->project->client_id]);

                if ($this->project->client_id && ! DB::table('client_vendor')->where('client_id', $this->project->client_id)->where('vendor_id', $ownerVendorId)->exists()) {
                    DB::table('client_vendor')->insert([
                        'client_id' => $this->project->client_id,
                        'vendor_id' => $ownerVendorId,
                        'source' => 'auto-attach',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                ProjectStatus::create([
                    'project_id' => $this->project->id,
                    'belongs_to_vendor_id' => $ownerVendorId,
                    'status_code' => ProjectStatus::getCodeForLabel('Invited') ?? 1,
                    'start_date' => today()->format('Y-m-d'),
                ]);
            }
        }

        app(\App\Http\Controllers\CompanyEmailController::class)
            ->saveExpenseReceipt($expense->id, $ocrData, $ocrFilename, null, false, true);

        Storage::disk('files')->delete($ocrPath);

        $this->reset('file');
        $this->belongs_to_vendor_id = null;
        $this->showUploadModal = false;

        $this->dispatch('$refresh');
    }

    #[Computed]
    public function materialExpenses()
    {
        return Expense::withoutGlobalScope(ExpenseScope::class)
            ->where('project_id', $this->project->id)
            ->with(['vendor', 'orderedReceipts'])
            ->whereHas('receipts', fn ($q) => $q->where('is_material_order', true))
            ->latest('date')
            ->get();
    }

    #[Computed]
    public function materialItems(): array
    {
        $allItems = [];

        foreach ($this->materialExpenses as $expense) {
            $receipt = $expense->orderedReceipts->first(fn ($r) => $r->is_material_order);

            if (! $receipt) {
                continue;
            }

            $items = $receipt->receipt_items['items'] ?? [];

            foreach ($items as $index => $item) {
                // Enrich with receipt_line_item_descs
                $desc = ReceiptLineItemDesc::where('expense_receipt_id', $receipt->id)
                    ->where('item_index', $index)
                    ->first();

                if ($desc) {
                    if (empty($item['image_url']) && ! empty($desc->product_image_url)) {
                        $item['image_url'] = $desc->product_image_url;
                    }

                    if (empty($item['product_url']) && ! empty($desc->product_url)) {
                        $item['product_url'] = $desc->product_url;
                    }

                    if (empty($item['Area']) && ! empty($desc->area)) {
                        $item['Area'] = $desc->area;
                    }
                }

                $item['_expense_id'] = $expense->id;
                $item['_item_index'] = $index;
                $item['_vendor_name'] = $expense->vendor?->business_name;
                $item['_order_number'] = $receipt->receipt_items['invoice_number'] ?? $expense->invoice;
                $item['_expense_date'] = $expense->date?->format('M j, Y');

                $allItems[] = $item;
            }
        }

        return $allItems;
    }

    #[Computed]
    public function materialItemsByExpense(): array
    {
        $grouped = [];

        foreach ($this->materialItems as $item) {
            $grouped[$item['_expense_id']][] = $item;
        }

        return $grouped;
    }

    #[Computed]
    public function materialAvailabilityByExpense(): array
    {
        $statusByExpense = [];

        foreach ($this->materialItemsByExpense as $expenseId => $items) {
            $availableCount = 0;
            $unavailableCount = 0;

            foreach ($items as $item) {
                if ($this->isItemAvailable($item)) {
                    $availableCount++;
                } else {
                    $unavailableCount++;
                }
            }

            if ($unavailableCount === 0) {
                $statusByExpense[$expenseId] = 'green';
            } elseif ($availableCount === 0) {
                $statusByExpense[$expenseId] = 'red';
            } else {
                $statusByExpense[$expenseId] = 'amber';
            }
        }

        return $statusByExpense;
    }

    private function isItemAvailable(array $item): bool
    {
        $status = MaterialOrderStatus::normalize($item['Status'] ?? null);

        if ($status === 'cancelled') {
            return false;
        }

        if (MaterialOrderStatus::isResolved($status)) {
            return true;
        }

        // For back order / open items, check if the ETA has passed (item likely arrived)
        $rawDate = $item['ETA'] ?? null;

        if (empty($rawDate)) {
            return ! in_array($status, ['back order', 'open'], true);
        }

        try {
            $vendorTz = auth()->user()?->vendor?->timezone ?? config('app.timezone');

            return ! Carbon::parse($rawDate)->startOfDay()->gt(now($vendorTz)->startOfDay());
        } catch (\Throwable) {
            return true;
        }
    }

    public function normalizeStatus(?string $raw): ?string
    {
        return MaterialOrderStatus::normalize($raw);
    }

    public function render()
    {
        return view('livewire.projects.project-materials');
    }

    /** Skeleton ceiling — the card lists every material order, so cap the shimmer. */
    public static function placeholderRows(): int
    {
        return 6;
    }

    public function placeholder(array $params = [])
    {
        $project = $params['project'] ?? null;
        $projectId = $project instanceof Project
            ? $project->id
            : (is_numeric($project) ? (int) $project : null);

        // A COUNT is far cheaper than the query the skeleton stands in for, so
        // the shimmer paints exactly as many rows as will arrive — and none at
        // all for a project with no material orders (the loaded card is
        // header-only there).
        $rows = $projectId
            ? Expense::withoutGlobalScope(ExpenseScope::class)
                ->where('project_id', $projectId)
                ->whereHas('receipts', fn ($q) => $q->where('is_material_order', true))
                ->count()
            : static::placeholderRows();

        return view('livewire.projects.project-materials-placeholder', [
            'project' => $project,
            'rows' => min($rows, static::placeholderRows()),
        ]);
    }
}
