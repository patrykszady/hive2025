<?php

namespace App\Livewire\Receipts;

use App\Models\Receipt;
use App\Models\Vendor;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class ReceiptsIndex extends Component
{
    use AuthorizesRequests;

    public ?int $editing_id = null;
    public ?int $vendor_id = null;
    public string $from_address = '';
    public array $from_subjects = [''];
    public ?int $receipt_type = 1;
    public array $options = [];

    public string $search = '';

    /**
     * Column defs for the receipts table — the real header row AND the loading
     * skeleton render from this one array, so widths can never drift apart.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(): array
    {
        return [
            ['label' => 'Vendor', 'width' => 'w-[24%] min-w-0', 'skeletonWidth' => 'w-28'],
            ['label' => 'From Address', 'width' => 'w-[30%] min-w-0', 'skeletonWidth' => 'w-40'],
            ['label' => 'Subject Match', 'width' => 'w-[24%] min-w-0', 'skeletonWidth' => 'w-32'],
            ['label' => 'Type', 'width' => 'w-[14%]', 'skeleton' => 'badge'],
            // Icon-only delete column. The real xs trash button is inset, so it
            // no longer drives the row height — a plain skeleton line matches.
            ['label' => '', 'width' => 'w-[8%]', 'skeletonWidth' => 'w-6'],
        ];
    }

    protected function rules(): array
    {
        return [
            'vendor_id' => 'required|exists:vendors,id',
            'from_address' => 'required|string|max:255',
            'from_subjects' => 'nullable|array',
            'from_subjects.*' => 'nullable|string|max:255',
            'receipt_type' => 'required|in:1,2',
            'options' => 'nullable|array',
            'options.receipt_start' => 'nullable|string',
            'options.receipt_end' => 'nullable|string',
            'options.receipt_start_offset' => 'nullable|string',
            'options.receipt_image_regex' => 'nullable|string',
            'options.query_fields' => 'nullable|string',
            'options.document_model' => 'nullable|string',
            'options.ocr' => 'nullable|string',
            'options.ocr_type' => 'nullable|string',
            'options.pdf_html' => 'nullable',
            'options.image_extension' => 'nullable|string',
        ];
    }

    #[Computed]
    public function receipts()
    {
        $query = Receipt::query()
            ->with('vendor')
            ->whereNotNull('receipt_type')
            ->where('receipt_type', '!=', 0)
            ->orderBy('id', 'desc');

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('from_address', 'like', "%{$search}%")
                  ->orWhere('from_subject', 'like', "%{$search}%")
                  ->orWhereHas('vendor', function ($vq) use ($search) {
                      $vq->where('business_name', 'like', "%{$search}%");
                  });
            });
        }

        return $query->get();
    }

    #[Computed]
    public function vendors()
    {
        return Vendor::withoutGlobalScopes()
            ->orderBy('business_name')
            ->get(['id', 'business_name']);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->modal('receipt_form_modal')->show();
    }

    public function edit(int $id): void
    {
        $receipt = Receipt::findOrFail($id);
        $this->editing_id = $receipt->id;
        $this->vendor_id = $receipt->vendor_id;
        $this->from_address = $receipt->from_address ?? '';
        $existingSubjects = $receipt->from_subject ?? [];
        if (! is_array($existingSubjects)) {
            $existingSubjects = [(string) $existingSubjects];
        }
        $this->from_subjects = empty($existingSubjects) ? [''] : array_values($existingSubjects);
        $this->receipt_type = $receipt->receipt_type;
        $this->options = is_array($receipt->options) ? $receipt->options : [];

        $this->modal('receipt_form_modal')->show();
    }

    public function store(): void
    {
        $this->validate();

        // Determine from_type based on from_address pattern
        $fromType = str_starts_with($this->from_address, '@') ? 2 : 3;

        // Filter out empty option values
        $cleanOptions = array_filter($this->options, fn ($v) => $v !== null && $v !== '');

        $cleanSubjects = array_values(array_filter(
            array_map('trim', $this->from_subjects),
            fn ($v) => $v !== ''
        ));

        $data = [
            'vendor_id' => $this->vendor_id,
            'from_type' => $fromType,
            'from_address' => $this->from_address,
            'from_subject' => empty($cleanSubjects) ? null : $cleanSubjects,
            'receipt_type' => $this->receipt_type,
            'options' => $cleanOptions ?: (object) [],
        ];

        if ($this->editing_id) {
            Receipt::where('id', $this->editing_id)->update($data);
            $message = 'Receipt Updated';
        } else {
            Receipt::create($data);
            $message = 'Receipt Created';
        }

        $this->modal('receipt_form_modal')->close();
        $this->resetForm();
        unset($this->receipts);

        Flux::toast(
            variant: 'success',
            heading: $message,
            duration: 3000,
        );
    }

    public function delete(int $id): void
    {
        Receipt::where('id', $id)->delete();
        unset($this->receipts);

        Flux::toast(
            variant: 'success',
            heading: 'Receipt Deleted',
            duration: 3000,
        );
    }

    protected function resetForm(): void
    {
        $this->editing_id = null;
        $this->vendor_id = null;
        $this->from_address = '';
        $this->from_subjects = [''];
        $this->receipt_type = 1;
        $this->options = [];
    }

    public function addSubject(): void
    {
        $this->from_subjects[] = '';
    }

    public function removeSubject(int $index): void
    {
        unset($this->from_subjects[$index]);
        $this->from_subjects = array_values($this->from_subjects);
        if (empty($this->from_subjects)) {
            $this->from_subjects = [''];
        }
    }

    #[Title('Email Receipts')]
    public function render()
    {
        return view('livewire.receipts.index');
    }
}
