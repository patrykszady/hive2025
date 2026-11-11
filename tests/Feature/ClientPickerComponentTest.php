<?php

use App\Livewire\Projects\ProjectsIndex;
use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * <x-client-picker> is the vendor picker's twin: Flux's searchable listbox
 * bound through wire:model, the list fetched from /clients/picker-options
 * and filtered in the browser, one printed option for the chosen client.
 * The /projects filter uses it in both layouts instead of rendering every
 * client as an option (866 KB of the page, 2026-11-06).
 */

/** Options inside the picker's own list (other Flux selects on a page have theirs). */
function clientPickerOptionCount(string $html): int
{
    $total = 0;
    $offset = 0;
    while (($start = strpos($html, 'data-client-picker-options>', $offset)) !== false) {
        $end = strpos($html, '</ui-options>', $start);
        $total += preg_match_all('/<ui-option[\s>]/', substr($html, $start, $end === false ? null : $end - $start));
        $offset = $start + 1;
    }

    return $total;
}

function clientPickerUser(): array
{
    $company = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $user = User::query()->create([
        'first_name' => 'Patryk', 'last_name' => 'Szady', 'email' => 'client-picker-ui-'.uniqid().'@example.test',
        'cell_phone' => '2245550009', 'password' => null, 'primary_vendor_id' => $company->id,
    ]);
    $user->vendors()->attach($company->id, ['role_id' => 1]);
    test()->actingAs($user);

    $clients = collect(range(1, 40))->map(function (int $i) use ($company) {
        $client = Client::factory()->create(['business_name' => "Client {$i} LLC"]);
        $client->vendors()->attach($company->id);

        return $client;
    });

    return [$user, $company, $clients];
}

it('is a Flux searchable listbox bound through wire:model, with no client list printed', function (): void {
    clientPickerUser();

    $html = Blade::render('<x-client-picker wire:model.live="client_id" label="Client" placeholder="All Clients..." clearable />');

    expect($html)->toContain('wire:model.live="client_id"')
        ->and($html)->toContain('x-modelable="value"')
        ->and($html)->toContain('clientPicker(')
        ->and($html)->toContain('picker-options')
        ->and($html)->toContain('All Clients...')
        ->and($html)->toContain('Search clients...')
        ->and(clientPickerOptionCount($html))->toBe(0);
});

it('prints only the chosen client, with its avatar, so the button has a name before the list arrives', function (): void {
    [, , $clients] = clientPickerUser();
    $chosen = $clients[3];

    $component = Livewire::test(ProjectsIndex::class)->set('client_id', $chosen->id);
    $html = $component->html();

    expect(clientPickerOptionCount($html))->toBe(2, 'one printed option per layout (mobile + desktop), nothing else')
        ->and($html)->toContain('label="'.e($chosen->name).'"')
        ->and($html)->toContain('data-flux-avatar');
});

it('keeps the /projects page light: no client options unless one is chosen', function (): void {
    clientPickerUser();

    $html = Livewire::test(ProjectsIndex::class)->html();

    expect(clientPickerOptionCount($html))->toBe(0)
        ->and(substr_count($html, 'Client 17 LLC'))->toBe(0);
});
