<?php

/**
 * DistributionProjectsForm::store()/storeBulk() synced
 * $this->distributions[*]['id'] (a client-controlled array) straight into
 * the project_distribution pivot with no check that the id was one
 * DistributionScope says belongs to my vendor.
 */

use App\Livewire\Distributions\DistributionProjectsForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/t3-fixtures.php';

it('lets an Admin assign their own distributions to their own project', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $mine = sec3_makeDistribution($vendor);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(DistributionProjectsForm::class)
        ->set('project', $project)
        ->set('distributions', [
            ['id' => $mine->id, 'name' => $mine->name, 'percent' => 100, 'amount' => null],
        ])
        ->call('store')
        ->assertHasNoErrors();

    expect($project->distributions()->pluck('distributions.id')->all())->toBe([$mine->id]);
});

it('never syncs another tenant\'s distribution id onto my project', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);

    $vendorB = sec3_makeVendor('Sec3 DistB');
    $foreign = sec3_makeDistribution($vendorB);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(DistributionProjectsForm::class)
        ->set('project', $project)
        ->set('distributions', [
            ['id' => $foreign->id, 'name' => $foreign->name, 'percent' => 100, 'amount' => null],
        ])
        ->call('store')
        ->assertHasNoErrors();

    expect($project->distributions()->pluck('distributions.id')->all())->toBe([]);
});
