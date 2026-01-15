<?php

namespace App\Livewire\Estimates;

use App\Livewire\Projects\ProjectFinances;

use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\Bid;
use App\Livewire\Estimates\EstimatesIndex;

use Flux;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Response;
use Livewire\Attributes\Title;
use Livewire\Component;

use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;

use Illuminate\Support\Number;

use Spatie\SimpleExcel\SimpleExcelWriter;
use App\Support\EstimateDocumentGenerator;

class EstimateShow extends Component
{
    use AuthorizesRequests;

    public Estimate $estimate;

    public $sections = [];

    public $trashedSections = [];

    protected $listeners = ['refreshComponent' => 'estimate_refresh'];

    protected function rules()
    {
        return [
            'sections.*.name' => 'required',
        ];
    }

    public function mount()
    {
        $this->sections = $this->estimate->estimate_sections->toArray();
        $this->trashedSections = $this->estimate->estimate_sections()->onlyTrashed()->get()->toArray();

        //11-1-2023 MOVE to EstiamteCreate
        //start with one section and an ADD card/button for line items
        if (empty($this->sections)) {
            $this->create_new_section();
            $this->estimate_refresh();
        } else {
            $this->estimate_refresh();
        }
    }

    public function estimate_refresh()
    {
        // Refresh the estimate model and eager load relationships
        $this->estimate = $this->estimate->fresh(['estimate_sections.estimate_line_items']);
        
        // Get fresh section data with updated totals from database
        $sections = $this->estimate->estimate_sections()
            ->with(['estimate_line_items', 'bid'])
            ->get();

        // If totals got out of sync (e.g. section was restored but total became 0), fix it.
        // Only repair obviously-broken totals to avoid unintended changes.
        $bidIdsToRecalculate = [];
        foreach ($sections as $section) {
            $computedTotal = (float) $section->estimate_line_items->sum('total');
            $storedTotal = (float) $section->total;

            if ($storedTotal === 0.0 && $computedTotal > 0.0) {
                $section->total = $computedTotal;
                $section->save();

                if (! empty($section->bid_id)) {
                    $bidIdsToRecalculate[] = $section->bid_id;
                }
            }
        }

        foreach (array_unique($bidIdsToRecalculate) as $bidId) {
            $bid = Bid::find($bidId);
            if (! $bid) {
                continue;
            }

            $bid->amount = EstimateSection::where('bid_id', $bid->id)->sum('total');
            $bid->save();

            if ((float) $bid->amount === 0.0) {
                $bid->delete();
            }
        }

        $this->sections = $sections->toArray();

        // Get trashed sections for restore functionality
        $this->trashedSections = $this->estimate->estimate_sections()
            ->onlyTrashed()
            ->get()
            ->toArray();
            
        // Notify EstimateAccept component to refresh its data
        $this->dispatch('refreshComponent')->to('estimates.estimate-accept');
    }

    public function create_new_section($name = null, $estimate_id = null)
    {
        $targetEstimateId = $this->estimate->id ?? $estimate_id;
        $currentMaxOrder = EstimateSection::query()
            ->where('estimate_id', $targetEstimateId)
            ->where('order', '<', 999999)
            ->max('order');

        $nextOrder = is_null($currentMaxOrder) ? 0 : $currentMaxOrder + 1;

        $section = EstimateSection::create([
            'estimate_id' => $targetEstimateId,
            'order' => $nextOrder,
            'name' => $name,
            'total' => 0.00,
            'deleted_at' => null,
        ]);

        $this->maybeCreateChangeOrderBid($section);

        return $section;
    }

    public function sectionAdd()
    {
        $this->create_new_section();
        $this->estimate_refresh();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Section Added',
            // route / href / wire:click
            text: 'Section Added',
        );
    }

    public function sectionRestore(int $sectionId)
    {
        $section = EstimateSection::withTrashed()->findOrFail($sectionId);
        $section->restore();

        $currentMaxOrder = EstimateSection::query()
            ->where('estimate_id', $section->estimate_id)
            ->where('order', '<', 999999)
            ->max('order');

        $section->order = is_null($currentMaxOrder) ? 0 : $currentMaxOrder + 1;
        $section->save();

        // Restore the line items without triggering observers that mutate section totals.
        EstimateLineItem::withoutEvents(function () use ($section) {
            $section->estimate_line_items()->onlyTrashed()->restore();
        });

        $section->total = $section->estimate_line_items()->sum('total');
        $section->save();

        $bid = $section->bid;
        
        // If the section had a bid_id but the bid was deleted, create a new change order bid
        if (! $bid && $section->bid_id) {
            $section->bid_id = null;
            $section->save();
            // Create a new change order bid for this restored section
            $this->maybeCreateChangeOrderBid($section);
            // Refresh section to get updated bid_id
            $section->refresh();
            $bid = $section->bid;
        }
        
        if ($bid) {
            $bid->amount = EstimateSection::where('bid_id', $bid->id)->sum('total');
            $bid->save();

            if ((float) $bid->amount === 0.0) {
                $bid->delete();
            }
        }

        $this->estimate_refresh();
        $this->dispatch('refresh')->to('projects.project-finances');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Section Restored',
            text: 'Section ' . ($section->name ?? 'Unnamed') . ' has been restored.',
        );
    }

    public function sectionDelete($section_index)
    {
        $section_data = $this->sections[$section_index];
        $section = EstimateSection::findOrFail($section_data['id']);

        // Push deleted sections to the end so restores can be reinserted cleanly.
        $section->order = 999999;
        $section->save();

        // Disable the section and its line items, but don't mutate stored totals.
        EstimateLineItem::withoutEvents(function () use ($section) {
            $section->estimate_line_items()->delete();
        });

        $section->delete();

        $bid = $section->bid;
        if ($bid) {
            $bid->amount = EstimateSection::where('bid_id', $bid->id)->sum('total');
            $bid->save();

            if ((float) $bid->amount === 0.0) {
                $bid->delete();
            }
        }

        $this->estimate_refresh();
        $this->dispatch('refresh')->to('projects.project-finances');

        Flux::toast(
            duration: 10000,
            position: 'top right',
            variant: 'success',
            heading: 'Section Disabled',
            text: 'Section and all its line items have been disabled.',
        );
    }

    public function sectionUpdate($section_index)
    {
        $section = EstimateSection::findOrFail($this->sections[$section_index]['id']);
        $section->name = $this->sections[$section_index]['name'];
        $section->save();
        $this->estimate_refresh();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Section Name Updated',
            // route / href / wire:click
            text: 'Section '.$section->name,
        );
    }

    protected function maybeCreateChangeOrderBid(EstimateSection $section): void
    {
        $estimate = $section->estimate;
        $project = $estimate?->project;
        $projectId = $estimate?->project_id;
        $vendorId = $estimate?->belongs_to_vendor_id;

        if (! $projectId || ! $vendorId || ! $project) {
            return;
        }

        // Ensure latestStatus is loaded
        $project->loadMissing('latestStatus');

        // Only auto-create change order bids for Active or Service Call projects
        $statusTitle = $project->latestStatus?->title;
        $activeStatuses = ['Active', 'Service Call'];

        if (! in_array($statusTitle, $activeStatuses, true)) {
            return;
        }

        $nextType = (int) (Bid::where('project_id', $projectId)
            ->where('vendor_id', $vendorId)
            ->max('type') ?? 1) + 1;

        $bid = Bid::create([
            'amount' => 0.00,
            'type' => $nextType,
            'project_id' => $projectId,
            'vendor_id' => $vendorId,
        ]);

        $section->bid_id = $bid->id;
        $section->save();
    }

    public function disableEstimate()
    {
        $projectId = $this->estimate->project->id;
        $this->estimate->delete();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Estimate Disabled',
            text: '',
        );

        return $this->redirect(route('projects.show', ['project' => $projectId]), navigate: true);
    }

    public function removeEstimate()
    {
        $projectId = $this->estimate->project->id;
        $this->estimate->delete();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Estimate Deleted',
            text: '',
        );

        return $this->redirect(route('projects.show', ['project' => $projectId]), navigate: true);
    }

    public function activateEstimate()
    {
        $this->estimate->restore();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Estimate Restored',
            text: '',
        );
    }

    public function sectionDuplicate($section_index)
    {
        $section_data = $this->sections[$section_index];
        $section = EstimateSection::findOrFail($section_data['id']);
        $line_items = $this->estimate->estimate_line_items()->where('section_id', $section->id)->get();

        $new_section = $this->create_new_section($section->name.' -Copy');

        //create new estimate section
        foreach ($line_items as $duplicate_section_line) {
            EstimateLineItem::create([
                'estimate_id' => $this->estimate->id,
                'line_item_id' => $duplicate_section_line->line_item_id,
                'section_id' => $new_section->id,
                'order' => $duplicate_section_line->order,
                'name' => $duplicate_section_line->name,
                'category' => $duplicate_section_line->category,
                'sub_category' => $duplicate_section_line->sub_category,
                'unit_type' => $duplicate_section_line->unit_type,
                'quantity' => $duplicate_section_line->quantity,
                'cost' => $duplicate_section_line->cost,
                'total' => $duplicate_section_line->total,
                'desc' => $duplicate_section_line->desc,
                'notes' => $duplicate_section_line->notes,
            ]);
        }

        $this->estimate_refresh();

        Flux::toast(
            duration: 10000,
            position: 'top right',
            variant: 'success',
            heading: 'Section Duplicated',
            // route / href / wire:click
            text: 'Section '.$new_section->name,
        );
    }

    public function getEstimateTotalProperty()
    {
        return collect($this->sections)->sum('total');
    }

    //$type = [estimate, invoice, work order]
    public function create_pdf($type)
    {
        $document = EstimateDocumentGenerator::generate($this->estimate, $type);

        // Force immediate component state preservation before download
        $this->skipRender();

        return response()->streamDownload(function () use ($document) {
            echo $document['binary'];
        }, $document['filename'], [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function sort_sections($key, $position)
    {
        $section = EstimateSection::findOrFail($key);
        $section->move($position);
        $this->estimate_refresh();
    }

    public function sort_line_item($key, $position)
    {
        $line_item = EstimateLineItem::findOrFail($key);
        $line_item->move($position);
        $this->estimate_refresh();
    }

    public function export_csv()
    {
        return response()->streamDownload(function () {
            $border = new Border(
                new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THICK, Border::STYLE_SOLID)
            );
            $border_thin = new Border(
                new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID)
            );

            $writer = SimpleExcelWriter::streamDownload($this->estimate->client->name.' - Estimate - '.$this->estimate->project->project_name.' - '.$this->estimate->number.'.xlsx')
                ->addHeader([
                    '',
                    'title',
                    'category',
                    'sub_category',
                    'quantity',
                    'unit',
                    'cost',
                    'total',
                ]);

            $writer->addRow([]);

            foreach ($this->estimate->estimate_sections as $index => $section) {
                $writer->addRow([
                    'title' => $section->name,
                    '',
                    'category' => null,
                    'sub_category' => null,
                    'quantity' => null,
                    'unit' => null,
                    'cost' => null,
                    'total' => $section->total,
                ], (new Style)->setFontBold()->setBorder($border));

                foreach ($section->estimate_line_items as $line_item) {
                    $writer->addRow([
                        '' => $index + 1 .'.'.$line_item->order + 1,
                        'title' => $line_item->name,
                        'category' => $line_item->category,
                        'sub_category' => $line_item->sub_category,
                        'quantity' => $line_item->quantity,
                        'unit' => $line_item->unit_type,
                        'cost' => $line_item->cost,
                        'total' => $line_item->total,
                    ]);
                }

                $writer->addRow([]);
            }

        }, $this->estimate->client->name.' - Estimate - '.$this->estimate->project->project_name.' - '.$this->estimate->number.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);

        //2024-12-25 disappearing toast when the above downloads
    }

    #[Title('Estimate')]
    public function render()
    {
        $this->authorize('view', $this->estimate);

        return view('livewire.estimates.show');
    }
}
