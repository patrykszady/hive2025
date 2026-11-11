<?php

use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Ui\CardsV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Cards v2 (config/ui.php, App\Support\Ui\CardsV2): the shared chrome —
 * x-island-card, x-details.card, x-form-modal and everything built on them
 * — renders Flux 2.21's card parts (header, heading, subheading, actions,
 * body, footer) when the flag is on for the page. Off everywhere by default;
 * dev turns it on for a page or two (/expenses, /hub) to adjust the layout
 * before the whole site moves (Patryk, 2026-11-06).
 */

/** The request the chrome is decided from. */
function cardsV2Request(string $uri, array $headers = [], string $method = 'GET'): void
{
    $request = Request::create($uri, $method);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }
    app()->instance('request', $request);
    CardsV2::reset();
}

/** The body PART's bare attribute; the card root always prints data-flux-card-body-variant. */
function cardsV2BodyPart(): string
{
    return '/data-flux-card-body(?![-\w])/';
}

function cardsV2On(array $paths = []): void
{
    config(['ui.cards_v2' => true, 'ui.cards_v2_paths' => $paths]);
    CardsV2::reset();
}

function cardsV2Actor(): array
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $user = User::query()->create([
        'first_name' => 'Cards', 'last_name' => 'Two', 'email' => 'cards-v2-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999), 'password' => null, 'primary_vendor_id' => $vendor->id,
    ]);
    $user->forceFill(['registration' => ['registered' => true]])->save();
    $user->vendors()->attach($vendor->id, ['role_id' => 1, 'is_employed' => 1]);

    return [$user, $vendor];
}

it('is off unless config/ui.php turns it on', function (): void {
    config(['ui.cards_v2' => false, 'ui.cards_v2_paths' => []]);
    cardsV2Request('/expenses');

    expect(CardsV2::active())->toBeFalse();
});

it('is on for the allow-listed page paths and their sub-pages only', function (string $path, bool $expected): void {
    cardsV2On(['/expenses', '/hub']);
    cardsV2Request($path);

    expect(CardsV2::active())->toBe($expected);
})->with([
    'the page itself' => ['/expenses', true],
    'a sub-page' => ['/expenses/29033', true],
    'the hub' => ['/hub', true],
    'a page that only starts with the same letters' => ['/expenses-report', false],
    'another page' => ['/projects', false],
    'the root' => ['/', false],
]);

it('is on everywhere when the path list is empty', function (): void {
    cardsV2On([]);
    cardsV2Request('/projects/347');

    expect(CardsV2::active())->toBeTrue();
});

it('reads the page address from the Referer on Livewire requests', function (): void {
    cardsV2On(['/expenses']);

    cardsV2Request('/livewire/update', ['X-Livewire' => 'true', 'Referer' => 'http://127.0.0.1:8000/expenses/12?tab=receipt'], 'POST');
    expect(CardsV2::active())->toBeTrue();

    cardsV2Request('/livewire/update', ['X-Livewire' => 'true', 'Referer' => 'http://127.0.0.1:8000/projects/347'], 'POST');
    expect(CardsV2::active())->toBeFalse();

    cardsV2Request('/livewire/update', ['X-Livewire' => 'true'], 'POST');
    expect(CardsV2::active())->toBeFalse();
});

it('decides once per request and again for the next one', function (): void {
    cardsV2On(['/expenses']);
    cardsV2Request('/expenses');
    expect(CardsV2::active())->toBeTrue();

    config(['ui.cards_v2' => false]);
    expect(CardsV2::active())->toBeTrue('the decision for this request stands');

    app()->instance('request', Request::create('/expenses'));
    expect(CardsV2::active())->toBeFalse('a new request decides again');
});

it('renders an island card on Flux card parts when on, and on the legacy markup when off', function (): void {
    $card = '<x-island-card heading="Expenses" subheading="This month" separator>'
        .'<x-slot:badge><span>12</span></x-slot:badge>'
        .'<x-slot:actions><button type="button">Add</button></x-slot:actions>'
        .'<x-slot:subheading_actions><button type="button">Sort</button></x-slot:subheading_actions>'
        .'<p>Body text</p>'
        .'<x-slot:footer><button type="button">Load more</button></x-slot:footer>'
        .'</x-island-card>';

    cardsV2On([]);
    cardsV2Request('/expenses');
    $v2 = Blade::render($card);

    expect($v2)->toContain('data-flux-card-header')
        ->and($v2)->toContain('data-flux-card-heading')
        ->and($v2)->toContain('data-flux-card-subheading')
        ->and($v2)->toContain('data-flux-card-actions')
        ->and($v2)->toMatch(cardsV2BodyPart())
        ->and($v2)->toContain('data-flux-card-body-variant="divided"')
        ->and($v2)->toContain('data-flux-card-footer')
        ->and($v2)->toContain('Expenses')->toContain('This month')->toContain('>12<')
        ->and($v2)->toContain('Add')->toContain('Sort')->toContain('Body text')->toContain('Load more')
        ->and($v2)->toContain('wire:transition.opacity.duration.200ms');

    config(['ui.cards_v2' => false]);
    CardsV2::reset();
    $legacy = Blade::render($card);

    expect($legacy)->not->toContain('data-flux-card-header')
        ->and($legacy)->not->toMatch(cardsV2BodyPart())
        ->and($legacy)->toContain('data-flux-heading')
        ->and($legacy)->toContain('Body text');
});

it('keeps a card without a separator seamless and makes a clickable heading toggle the card', function (): void {
    cardsV2On([]);
    cardsV2Request('/expenses');

    $html = Blade::render('<div x-data="{ open: false }"><x-island-card heading="Notes" clickable><p>Body</p></x-island-card></div>');

    expect($html)->toContain('data-flux-card-body-variant="seamless"')
        ->and($html)->toContain('x-on:click="open = !open"')
        ->and($html)->toContain('role="button"');
});

it('renders a details card on Flux card parts with its header buttons, toggle, details and footer', function (): void {
    cardsV2On([]);
    cardsV2Request('/expenses/1');

    $html = Blade::render('<x-details.card title="Receipt" subheading="Lowe and Sons">'
        .'<x-slot:title_extras><span>paid</span></x-slot:title_extras>'
        .'<x-slot:header_buttons><button type="button">Edit</button></x-slot:header_buttons>'
        .'<p>Summary</p>'
        .'<x-slot:details><p>Line items</p></x-slot:details>'
        .'<x-slot:footer><button type="button">Save</button></x-slot:footer>'
        .'</x-details.card>');

    expect($html)->toContain('data-flux-card-header')
        ->and($html)->toContain('data-flux-card-heading')
        ->and($html)->toContain('data-flux-card-subheading')
        ->and($html)->toMatch(cardsV2BodyPart())
        ->and($html)->toContain('data-flux-card-footer')
        ->and($html)->toContain('Receipt')->toContain('Lowe and Sons')->toContain('paid')
        ->and($html)->toContain('Edit')->toContain('Summary')->toContain('Line items')->toContain('Save')
        ->and($html)->toContain('x-data="{ open: true }"')
        ->and($html)->toContain('x-collapse')
        ->and($html)->not->toContain('data-flux-separator');
});

it('renders a form modal with a standalone Flux card header and footer when on', function (): void {
    $modal = '<x-form-modal name="edit-expense" title="Edit expense">'
        .'<x-slot:subtitle>Lowe and Sons, Oct 12</x-slot:subtitle>'
        .'<x-slot:headerActions><button type="button">Delete</button></x-slot:headerActions>'
        .'<p>Fields</p>'
        .'<x-slot:footer><button type="button">Save</button></x-slot:footer>'
        .'</x-form-modal>';

    cardsV2On([]);
    cardsV2Request('/expenses');
    $v2 = Blade::render($modal);

    expect($v2)->toContain('data-flux-card-header')
        ->and($v2)->toContain('data-flux-card-standalone')
        ->and($v2)->toContain('data-flux-card-heading')
        ->and($v2)->toContain('data-flux-card-subheading')
        ->and($v2)->toContain('data-flux-card-actions')
        ->and($v2)->toContain('data-flux-card-footer')
        ->and($v2)->toContain('Edit expense')->toContain('Lowe and Sons, Oct 12')->toContain('Delete')->toContain('Fields')->toContain('Save');

    config(['ui.cards_v2' => false]);
    CardsV2::reset();
    $legacy = Blade::render($modal);

    expect($legacy)->not->toContain('data-flux-card-header')
        ->and($legacy)->not->toContain('data-flux-card-footer')
        ->and($legacy)->toContain('Edit expense')->toContain('Save');
});

it('renders an inline form modal with the same parts', function (): void {
    cardsV2On([]);
    cardsV2Request('/expenses');

    $html = Blade::render('<x-form-modal name="new-split" title="New split" inline><p>Fields</p><x-slot:footer><button type="button">Add</button></x-slot:footer></x-form-modal>');

    expect($html)->toContain('data-flux-card-header')
        ->and($html)->toContain('data-flux-card-footer')
        ->and($html)->toContain('New split')->toContain('Add');
});

it('leaves a modal with its own header slot alone', function (): void {
    cardsV2On([]);
    cardsV2Request('/expenses');

    $html = Blade::render('<x-form-modal name="custom" title="Ignored"><x-slot:header><h2>Custom header</h2></x-slot:header><p>Fields</p></x-form-modal>');

    expect($html)->toContain('Custom header')
        ->and($html)->not->toContain('data-flux-card-header');
});

it('shows the new chrome on /expenses, an expense and /hub when they are allow-listed, and nowhere else', function (): void {
    [$user, $vendor] = cardsV2Actor();
    $expense = Expense::query()->create([
        'amount' => 120.50, 'date' => '2026-10-12', 'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $user->id,
    ]);
    $this->actingAs($user);

    config(['ui.cards_v2' => true, 'ui.cards_v2_paths' => ['/expenses', '/hub']]);

    foreach (['/expenses', '/expenses/'.$expense->id, '/hub'] as $path) {
        CardsV2::reset();
        $html = $this->get($path)->assertOk()->getContent();
        expect(str_contains($html, 'data-flux-card-header'))->toBeTrue("a Flux card header on {$path}")
            ->and((bool) preg_match(cardsV2BodyPart(), $html))->toBeTrue("a Flux card body on {$path}");
    }

    CardsV2::reset();
    $projects = $this->get('/projects')->assertOk()->getContent();
    expect($projects)->not->toContain('data-flux-card-header');

    config(['ui.cards_v2' => false]);
    CardsV2::reset();
    $off = $this->get('/expenses')->assertOk()->getContent();
    expect($off)->not->toContain('data-flux-card-header')
        ->and($off)->toContain('data-flux-heading');
});

/**
 * The chrome's root branch is raw PHP, not @if: inside a Livewire render an
 * @if emits <!--[if BLOCK]> markers, and a component rooted on the card then
 * began with a marker instead of its element — the morph nested the loaded
 * Projects table inside its own skeleton (2026-11-06).
 */
it('keeps a Livewire component rooted on a card rooted on the element, on or off', function (string $template): void {
    foreach ([true, false] as $on) {
        config(['ui.cards_v2' => $on, 'ui.cards_v2_paths' => []]);
        CardsV2::reset();

        $component = new class extends Component
        {
            public string $template = '';

            public function render(): string
            {
                return $this->template;
            }
        };
        $html = ltrim(Livewire::test($component, ['template' => $template])->html());

        expect(str_starts_with($html, '<div'))->toBeTrue('the root is the card element ('.($on ? 'v2' : 'legacy').'): '.substr($html, 0, 80));
    }
})->with([
    'island card' => ['<x-island-card heading="Probe"><p>Body</p></x-island-card>'],
    'details card' => ['<x-details.card title="Probe"><p>Body</p></x-details.card>'],
    'details card with a toggle' => ['<x-details.card title="Probe"><p>Body</p><x-slot:details><p>More</p></x-slot:details></x-details.card>'],
]);
