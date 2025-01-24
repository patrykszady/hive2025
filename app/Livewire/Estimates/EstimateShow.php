<?php

namespace App\Livewire\Estimates;

use App\Jobs\SendInitialEstimateEmail;
use App\Livewire\Projects\ProjectFinances;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
// use App\Livewire\Estimates\EstimatesIndex;

use App\Models\EstimateSection;
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

    protected $listeners = ['refreshComponent' => '$refresh'];

    protected function rules()
    {
        return [
            'sections.*.name' => 'required',
            'sections.*.items_rearrange' => 'nullable',
        ];
    }

    public function mount()
    {
        $this->sections =
            $this->estimate->estimate_sections;
        // ->each(function ($item, $key) {
        //     $item->items_rearrange = FALSE;
        // });

        //11-1-2023 MOVE to EstiamteCreate
        //start with one section and an ADD card/button for line items
        if ($this->sections->isEmpty()) {
            $this->create_new_section();
            $this->estimate_refresh();
        }
    }

    public function estimate_refresh()
    {
        $this->estimate->refresh();
        $this->sections = $this->estimate->estimate_sections;
    }

    public function create_new_section($name = null)
    {
        return EstimateSection::create([
            'estimate_id' => $this->estimate->id,
            'index' => $this->sections->isEmpty() ? 0 : $this->sections->max('index') + 1,
            'name' => $name,
            'total' => 0.00,
            'deleted_at' => null,
        ]);
    }

    public function sectionAdd()
    {
        $this->create_new_section();
        $this->estimate_refresh();

        $this->dispatch('notify',
            type: 'success',
            content: 'Section Added'
        );
    }

    public function sectionRemove($section_index)
    {
        $section = $this->sections[$section_index];
        $estimate_line_items = $this->estimate->estimate_line_items()->where('section_id', $section->id)->get();

        foreach ($estimate_line_items as $estimate_line_item) {
            $estimate_line_item->delete();
        }

        $section->delete();
        $this->estimate_refresh();

        Flux::toast(
            duration: 10000,
            position: 'top right',
            variant: 'success',
            heading: 'Section Removed',
            // route / href / wire:click
            text: 'Section '.$section->name,
        );

        //dispatch to refresh on project finances
        $this->dispatch('refresh')->to(ProjectFinances::class);
    }

    public function sectionUpdate($section_index)
    {
        $section = EstimateSection::findOrFail($this->sections[$section_index]['id']);
        $section->name = $this->sections[$section_index]['name'];
        //ignore 'bid_index' attribute when saving
        //OR put    // public $items_rearrange; on Model
        // $section->offsetUnset('items_rearrange');
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

    // public function itemsRearrange($section_index)
    // {
    //     $section = $this->sections[$section_index];

    //     if($section->items_rearrange == FALSE){
    //         $section->items_rearrange = TRUE;
    //     }else{
    //         $section->items_rearrange = FALSE;
    //     }
    // }

    public function sectionDuplicate($section_index)
    {
        $section = $this->sections[$section_index];
        $line_items = $this->estimate->estimate_line_items()->where('section_id', $section->id)->get();
        $section_to_duplicate = $this->estimate->estimate_sections()->where('id', $section->id)->first();

        $section = $this->create_new_section($section_to_duplicate->name.' -Copy');

        //create new estimate section
        foreach ($line_items as $duplicate_section_line) {
            EstimateLineItem::create([
                'estimate_id' => $this->estimate->id,
                'line_item_id' => $duplicate_section_line->line_item_id,
                'section_id' => $section->id,
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
            text: 'Section '.$section->name,
        );
    }

    public function getEstimateTotalProperty()
    {
        return $this->sections->sum('total');
    }

    //$type = [estimate, invoice, work order]
    public function print($type)
    {
        $headers =
            [
                'Content-Type: application/pdf',
            ];

        $data = $this->create_pdf($this->estimate, $this->sections, $type);

        return Response::download($data[0], $data[1].'.pdf', $headers);

        //2024-12-25
        // if($type == 'estimate'){
        //     // SendInitialEstimateEmail::dispatch($this->estimate, $this->sections, $type);
        //}
    }

    public function create_pdf($estimate, $sections, $type)
    {
        $estimate_total = $sections->sum('total');
        $type = ucwords(strtolower($type));

        $estimate_total_words =
            ucwords(
                Number::spell((int)$estimate_total) . ' dollars and ' .
                Number::spell((int)(($estimate_total - (int)$estimate_total) * 100)) . ' cents'
            );

        $payments = $estimate->project->payments->where('belongs_to_vendor_id', $estimate->vendor->id);

        $title = $estimate->client->name.' | '.$type.' | '.$estimate->project->project_name.' | '.$estimate->number;
        $title_file = $estimate->client->name.' - '.$type.' - '.$estimate->project->project_name.' - '.$estimate->number;

        $view = view('misc.estimate', compact(['estimate', 'sections', 'payments', 'title', 'estimate_total', 'estimate_total_words', 'type']))->render();
        $location = storage_path('files/pdfs/'.$title_file.'.pdf');

        Browsershot::html($view)
            ->newHeadless()
            ->scale(0.8)
            ->showBrowserHeaderAndFooter()
            ->margins(10, 5, 10, 5)
            ->save($location);

        return [$location, $title_file];
    }

    public function sort($key, $position)
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

    public function deleteEstimate()
    {
        $this->estimate->delete();

        Flux::toast(
            duration: 10000,
            position: 'top right',
            variant: 'success',
            heading: 'Estimate Removed',
            // route / href / wire:click
            text: '',
        );

        //2024-12-25 dispatch to EstimatesIndex deleteEstimate
        // $this->dispatch('deleteEstimate')->to(EstimatesIndex::class);
        $this->redirectRoute('projects.show', ['project' => $this->estimate->project->id]);
    }

    #[Title('Estimate')]
    public function render()
    {
        $this->authorize('view', $this->estimate);

        return view('livewire.estimates.show');
    }
}
