<?php

use App\Livewire\Ewccv\EwccvBrowserViewer;
use App\Models\User;
use App\Models\Vendor;
use App\Services\EwccvRemoteBrowserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * /ewccv/browser embeds the EWCCV server browser's viewer like the Menards
 * page does, for the owner company's admins only (2026-10-14).
 */
function ewccvViewerAdmin(): User
{
    $vendor = Vendor::factory()->create(['business_name' => 'Owner LLC']);
    config(['services.menards.owner_vendor_id' => $vendor->id]);
    $user = User::query()->create([
        'first_name' => 'Ewccv', 'last_name' => 'Admin', 'email' => 'ewccv-viewer-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999), 'password' => null, 'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

function fakeEwccvBrowser(bool $running): void
{
    app()->instance(EwccvRemoteBrowserService::class, new class($running) extends EwccvRemoteBrowserService
    {
        public function __construct(private bool $running) {}

        public function status(): array
        {
            return ['display' => $this->running, 'chrome' => $this->running, 'vnc' => $this->running, 'viewer' => $this->running, 'extension_configured' => true];
        }
    });
}

it('embeds the viewer while the browser runs, with a Stop button', function (): void {
    fakeEwccvBrowser(true);

    Livewire::actingAs(ewccvViewerAdmin())
        ->test(EwccvBrowserViewer::class)
        ->assertSee('/ewccv-vnc/vnc.html')
        ->assertSee('wire:click="stop"', false);
});

it('offers Start when the browser is not running', function (): void {
    fakeEwccvBrowser(false);

    Livewire::actingAs(ewccvViewerAdmin())
        ->test(EwccvBrowserViewer::class)
        ->assertSee('The browser is not running')
        ->assertSee('wire:click="start"', false)
        ->assertDontSee('/ewccv-vnc/vnc.html');
});

it('is for the owner company only, and nginx auth refuses guests', function (): void {
    fakeEwccvBrowser(true);
    $admin = ewccvViewerAdmin();
    $other = Vendor::factory()->create(['business_name' => 'Elsewhere Inc']);
    $outsider = User::query()->create([
        'first_name' => 'Out', 'last_name' => 'Sider', 'email' => 'ewccv-out-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999), 'password' => null, 'primary_vendor_id' => $other->id,
    ]);
    $other->users()->attach($outsider->id, ['role_id' => 1]);

    $this->get('/ewccv-vnc-auth')->assertForbidden();
    $this->actingAs($admin)->get('/ewccv-vnc-auth')->assertNoContent();
    $this->actingAs($outsider)->get('/ewccv-vnc-auth')->assertForbidden();
    Livewire::actingAs($outsider)->test(EwccvBrowserViewer::class)->assertForbidden();
});
