<?php

use App\Livewire\Clients\ClientsIndex;
use App\Livewire\Concerns\RemembersFilters;
use App\Livewire\Projects\ProjectsIndex;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** A page with one aliased filter, one unaliased one and a "Clear filters" action. */
class RememberedFiltersTestPage extends Component
{
    use RemembersFilters;

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(except: '')]
    public string $search = '';

    public bool $embedded = false;

    public function clearFilters(): void
    {
        $this->statusFilter = '';
        $this->search = '';
    }

    protected function rememberedFilters(): array
    {
        return $this->embedded ? [] : ['statusFilter', 'search'];
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

function filtersUser(): User
{
    $vendor = Vendor::factory()->create();
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

it('brings /projects back on the statuses it was left on, not Active', function () {
    $user = filtersUser();

    Livewire::actingAs($user)->test(ProjectsIndex::class)
        ->set('project_status_title', [3, 8]);

    Livewire::actingAs($user)->test(ProjectsIndex::class)
        ->assertSet('project_status_title', [3, 8]);
});

it('remembers All as well: a bare /projects stays on every status', function () {
    $user = filtersUser();

    Livewire::actingAs($user)->test(ProjectsIndex::class)
        ->set('project_status_title', []);

    Livewire::actingAs($user)->test(ProjectsIndex::class)
        ->assertSet('project_status_title', []);
});

it('lets a link that carries filters win over the remembered ones', function () {
    $user = filtersUser();

    Livewire::actingAs($user)->test(ProjectsIndex::class)
        ->set('project_status_title', [3]);

    Livewire::actingAs($user)
        ->withQueryParams(['project_status_title' => 6])
        ->test(ProjectsIndex::class)
        ->assertSet('project_status_title', [6]);
});

it('keeps each person\'s filters to themselves', function () {
    $patryk = filtersUser();
    $greg = filtersUser();

    Livewire::actingAs($patryk)->test(ClientsIndex::class)
        ->set('client_name_search', 'Hale');

    Livewire::actingAs($greg)->test(ClientsIndex::class)
        ->assertSet('client_name_search', '');
    Livewire::actingAs($patryk)->test(ClientsIndex::class)
        ->assertSet('client_name_search', 'Hale');
});

it('keeps a Clear filters action, which resets properties without wire:model', function () {
    $user = filtersUser();

    Livewire::actingAs($user)->test(RememberedFiltersTestPage::class)
        ->set('statusFilter', 'signed')
        ->set('search', 'Hale')
        ->call('clearFilters');

    Livewire::actingAs($user)->test(RememberedFiltersTestPage::class)
        ->assertSet('statusFilter', '')
        ->assertSet('search', '');
});

it('reads a filter\'s URL alias: ?status= is a link with filters', function () {
    $user = filtersUser();

    Livewire::actingAs($user)->test(RememberedFiltersTestPage::class)
        ->set('statusFilter', 'signed')
        ->set('search', 'Hale');

    Livewire::actingAs($user)
        ->withQueryParams(['status' => 'pending'])
        ->test(RememberedFiltersTestPage::class)
        ->assertSet('statusFilter', 'pending')
        ->assertSet('search', '');
});

it('neither saves nor restores while the list is embedded in another page', function () {
    $user = filtersUser();

    Livewire::actingAs($user)->test(RememberedFiltersTestPage::class)
        ->set('search', 'Hale');

    Livewire::actingAs($user)->test(RememberedFiltersTestPage::class, ['embedded' => true])
        ->assertSet('search', '')
        ->set('search', 'Andersen');

    Livewire::actingAs($user)->test(RememberedFiltersTestPage::class)
        ->assertSet('search', 'Hale');
});

it('restores nothing on a first visit', function () {
    Livewire::actingAs(filtersUser())->test(RememberedFiltersTestPage::class)
        ->assertSet('statusFilter', '')
        ->assertSet('search', '');
});

it('remembers the filters a link arrived with, for the next bare visit', function () {
    $user = filtersUser();

    Livewire::actingAs($user)
        ->withQueryParams(['project_status_title' => 3])
        ->test(ProjectsIndex::class);

    Livewire::actingAs($user)->test(ProjectsIndex::class)
        ->assertSet('project_status_title', [3]);
});

it('forgets the filters after fifteen minutes away: /projects opens on Active again', function () {
    $user = filtersUser();

    Livewire::actingAs($user)->test(ProjectsIndex::class)
        ->set('project_status_title', [3]);

    $this->travel(14)->minutes();
    Livewire::actingAs($user)->test(ProjectsIndex::class)
        ->assertSet('project_status_title', [3]);

    // That visit renewed the fifteen minutes; now stay away longer.
    $this->travel(16)->minutes();
    Livewire::actingAs($user)->test(ProjectsIndex::class)
        ->assertSet('project_status_title', fn ($statuses) => $statuses !== [3]);
});
