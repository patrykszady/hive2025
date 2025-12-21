<?php

namespace App\Livewire\Distributions;

use App\Models\Distribution;
use App\Models\Project;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DistributionProjectsForm extends Component
{
    use AuthorizesRequests;

    public ?Project $project = null;

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

    protected $listeners = ['addDis'];

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

        $this->distributions = Distribution::query()
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (Distribution $distribution): array => [
                'id' => (int) $distribution->id,
                'name' => (string) $distribution->name,
                'percent' => null,
                'amount' => null,
            ])
            ->all();
    }

    public function getPercentSumProperty(): int
    {
        $this->recalculateTotals();

        return $this->percent_distributions_sum;
    }

    private function recalculateTotals(): void
    {
        $this->percent_distributions_sum = (int) collect($this->distributions)
            ->pluck('percent')
            ->filter(fn ($percent) => is_numeric($percent) && (int) $percent > 0)
            ->sum();

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

        $this->resetModal();

        $existing = $this->project->distributions
            ->mapWithKeys(fn (Distribution $distribution): array => [
                (int) $distribution->id => [
                    'percent' => (int) $distribution->pivot->percent,
                    'amount' => (float) $distribution->pivot->amount,
                ],
            ]);

        foreach ($this->distributions as $index => $row) {
            $existingRow = $existing->get((int) $row['id']);

            if ($existingRow) {
                $this->distributions[$index]['percent'] = $existingRow['percent'];
                $this->distributions[$index]['amount'] = $existingRow['amount'];
            }
        }

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

        $profit = (float) data_get($this->project->finances ?? [], 'profit', 0);

        $syncData = [];

        foreach ($this->distributions as $row) {
            $percent = (int) ($row['percent'] ?? 0);

            if ($percent <= 0) {
                continue;
            }

            $syncData[(int) $row['id']] = [
                'percent' => $percent,
                'amount' => round($profit * ($percent / 100), 2),
            ];
        }

        $this->project->distributions()->sync($syncData);

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

    public function render()
    {
        return view('livewire.distributions.projects-form', [
        ]);
    }
}
