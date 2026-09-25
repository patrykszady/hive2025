<?php

use App\Livewire\Projects\ProjectsTable;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * ProjectsTable::projects() eager-loaded 'statuses' (a project's FULL
 * status history) for every row on the /projects index (1.2MB body) even
 * though nothing reads it — the Status column renders from
 * latestVendorStatus(), which queries directly. This confirms the page
 * still renders correctly (the search itself runs through Scout/
 * Meilisearch, which the test config points at the null driver, so this
 * covers rendering/columns — the with()-list content change itself was
 * verified by code review: 'statuses' was the only relation removed;
 * 'client.users' (Client::lastNames() reads $this->users when
 * business_name is blank) and 'createdByVendor' (used in the Contractor
 * column) are both still read by the blade and were left in place).
 */
it('renders the projects table without erroring after dropping the unused statuses eager load', function () {
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    $user = User::query()->create([
        'first_name' => 'Table', 'last_name' => 'Admin',
        'email' => 'table-admin-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);
    $this->actingAs($user);

    $client = Client::factory()->create();
    Project::factory()->create(['belongs_to_vendor_id' => $vendor->id, 'client_id' => $client->id]);

    Livewire::test(ProjectsTable::class)->assertOk();
});
