<?php

namespace App\Livewire\Estimates;

use App\Jobs\SendInitialEstimateEmail;
use App\Livewire\Projects\ProjectFinances;

use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
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

use Spatie\Browsershot\Browsershot;
use Spatie\SimpleExcel\SimpleExcelWriter;

class EstimateShow extends Component
{
    use AuthorizesRequests;

    public Estimate $estimate;

    public $sections = [];

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

        //11-1-2023 MOVE to EstiamteCreate
        //start with one section and an ADD card/button for line items
        if (empty($this->sections)) {
            $this->create_new_section();
            $this->estimate_refresh();
        }
    }

    public function estimate_refresh()
    {
        // Refresh the estimate model and eager load relationships
        $this->estimate = $this->estimate->fresh(['estimate_sections.estimate_line_items']);
        
        // Get fresh section data with updated totals from database
        $this->sections = $this->estimate->estimate_sections()
            ->with('estimate_line_items')
            ->get()
            ->toArray();
            
        // Notify EstimateAccept component to refresh its data
        $this->dispatch('refreshComponent')->to('estimates.estimate-accept');
    }

    public function create_new_section($name = null, $estimate_id = null)
    {
        return EstimateSection::create([
            'estimate_id' => $this->estimate->id ?? $estimate_id,
            'order' => empty($this->sections) ? 0 : collect($this->sections)->max('order') + 1,
            'name' => $name,
            'total' => 0.00,
            'deleted_at' => null,
        ]);
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

    public function sectionDelete($section_index)
    {
        $section_data = $this->sections[$section_index];
        $section = EstimateSection::findOrFail($section_data['id']);

        // Get all line items for this section
        $line_items = $this->estimate->estimate_line_items()->where('section_id', $section->id)->get();

        // Delete all line items first
        foreach ($line_items as $line_item) {
            $line_item->delete();
        }

        // Delete the section
        $section->delete();

        $this->estimate_refresh();

        Flux::toast(
            duration: 10000,
            position: 'top right',
            variant: 'success',
            heading: 'Section Deleted',
            text: 'Section and all its line items have been deleted.',
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

    // public function disableEstimate()
    // {
    //     $this->dispatch('disableEstimate', ['estimate' => $this->estimate->id])->to(EstimatesIndex::class);
    //     // $this->dispatch('estimates.estimates-index', 'disableEstimate', ['estimate' => $this->estimate->id]);
    // }

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
        // Force component refresh before PDF generation
        $this->estimate = $this->estimate->fresh();
        $this->sections = $this->estimate->estimate_sections;
        
        $estimate = $this->estimate;
        $sections = $this->sections;
        $type = ucwords(strtolower($type));
        $estimate_total = $sections->sum('total');

        $estimate_total_words =
        ucwords(
            Number::spell((int)$estimate_total) . ' dollars and ' .
            Number::spell((int)(($estimate_total - (int)$estimate_total) * 100)) . ' cents'
        );

        $payments = $estimate->project->payments->where('belongs_to_vendor_id', $estimate->vendor->id);

        $title = $estimate->client->name.' - '.$type.' - '.$estimate->project->project_name.' - '.$estimate->number;
        $view = view('misc.estimate', compact(['estimate', 'sections', 'payments', 'title', 'estimate_total', 'estimate_total_words', 'type']))->render();

        $pdf = Browsershot::html($view)
            ->newHeadless()
            ->addChromiumArguments([
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--single-process',
            ])
            ->scale(0.8)
            ->showBrowserHeaderAndFooter()
            ->showBackground()
            // ->headerHtml('Header')
            // ->footerHtml('<span class="pageNumber"></span>')
            //->margins($top, $right, $bottom, $left)
            ->margins(10, 5, 10, 5)
            ->pdf();

        // Force immediate component state preservation before download
        $this->skipRender();

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf;
        }, $title.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);

          //     //2024-12-25
    //     // if($type == 'estimate'){
    //     //     // SendInitialEstimateEmail::dispatch($this->estimate, $this->sections, $type);
    //     //}
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
