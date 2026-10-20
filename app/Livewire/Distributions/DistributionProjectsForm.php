<?php

namespace App\Livewire\Distributions;

use App\Models\Distribution;
use App\Models\Project;
use App\Services\Distributions\DistributionProjectAssignment;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DistributionProjectsForm extends Component
{
    use AuthorizesRequests;

    public ?Project $project = null;

    public bool $isBulkMode = false;

    /**
     * @var array<int, array{id:int,name:string,percent:int|null,amount:float|null}>
     */
    public array $distributions = [];

    public int $percent_distributions_sum = 0;

    public $view_text = [
        'card_title' => 'New Distributions',
        'button_text' => 'Save Distributions',
        'form_submit' => 'store',
    ];

    protected $listeners = ['addDis', 'bulkAssign'];

    protected function rules()
    {
        return [
            'distributions.*.percent' => 'nullable|integer|min:0|max:100',
            'percent_distributions_sum' => 'required|integer|min:100|max:100',
        ];
    }

    protected $messages =
        [
            'percent_distributions_sum' => 'Percent sum must equal to 100%',
            'expense_splits.*.amount.required_if' => 'The split amount field is required.',
            'expense_splits.*.amount.numeric' => 'The amount field must be numberic.',
        ];

    public function updated($field, $value)
    {
        if (preg_match('/^distributions\.(\d+)\.percent$/', (string) $field, $matches) === 1) {
            if ($value === '') {
                $this->distributions[(int) $matches[1]]['percent'] = null;
            }
        }

        if (str_starts_with((string) $field, 'distributions.')) {
            $this->recalculateTotals();
        }

        $this->validateOnly($field);
    }

    public function mount(): void
    {
        $this->resetModal();
    }

    public function resetModal(): void
    {
        $this->resetErrorBag();
        $this->percent_distributions_sum = 0;

        $this->distributions = app(DistributionProjectAssignment::class)->rows(null);
    }

    public function getPercentSumProperty(): int
    {
        $this->recalculateTotals();

        return $this->percent_distributions_sum;
    }

    private function recalculateTotals(): void
    {
        $this->percent_distributions_sum = app(DistributionProjectAssignment::class)->percentSum(
            collect($this->distributions)->pluck('percent', 'id')->all()
        );

        $profit = (float) data_get($this->project?->finances ?? [], 'profit', 0);

        foreach ($this->distributions as $index => $row) {
            $percent = (int) ($row['percent'] ?? 0);

            if ($percent > 0) {
                $this->distributions[$index]['amount'] = round($profit * ($percent / 100), 2);
            } else {
                $this->distributions[$index]['amount'] = null;
            }
        }
    }

    public function addDis(Project $project): void
    {
        $this->authorize('viewAny', Distribution::class);

        $this->project = $project;
        $this->project->loadMissing(['distributions', 'client.users']);

        $this->resetErrorBag();
        $this->distributions = app(DistributionProjectAssignment::class)->rows($this->project);

        $this->recalculateTotals();
        $this->modal('project_distributions_modal')->show();
    }

    public function store(): void
    {
        $this->authorize('viewAny', Distribution::class);

        if (!$this->project) {
            return;
        }

        $this->recalculateTotals();
        $this->validate();

        app(DistributionProjectAssignment::class)->assign(
            $this->project,
            collect($this->distributions)->pluck('percent', 'id')->all()
        );

        $this->modal('project_distributions_modal')->close();

        $this->dispatch('refreshComponent')->to('distributions.distributions-index');

        Flux::toast(
            duration: 4000,
            position: 'top right',
            variant: 'success',
            heading: 'Project distributions updated.',
            text: 'Saved distribution splits for '.$this->project->short_address.'.',
        );
    }

    public function bulkAssign(): void
    {
        $this->authorize('viewAny', Distribution::class);

        $this->project = null;
        $this->isBulkMode = true;
        $this->resetModal();

        $this->modal('bulk_distributions_modal')->show();
    }

    public function storeBulk(): void
    {
        $this->authorize('viewAny', Distribution::class);

        $this->validate([
            'distributions.*.percent' => 'nullable|integer|min:0|max:100',
            'percent_distributions_sum' => 'required|integer|min:100|max:100',
        ]);

        $count = app(DistributionProjectAssignment::class)->assignBulk(
            collect($this->distributions)->pluck('percent', 'id')->all()
        );

        $this->isBulkMode = false;
        $this->modal('bulk_distributions_modal')->close();

        $this->dispatch('refreshComponent')->to('distributions.distributions-index');

        Flux::toast(
            duration: 4000,
            position: 'top right',
            variant: 'success',
            heading: 'Bulk distributions assigned.',
            text: "Assigned distributions to {$count} projects.",
        );
    }

    public function render()
    {
        return view('livewire.distributions.projects-form', [
        ]);
    }
}
