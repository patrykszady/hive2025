<?php

use App\Models\ShortLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// The `throttle` middleware counts against the array cache, which — unlike
// the database — is NOT reset by RefreshDatabase and persists for the whole
// test process. Flush it around these tests so a throttle test can't leave
// another test's requests to the same routes blocked, and doesn't inherit a
// stale count from one that ran first.
beforeEach(fn () => Cache::flush());
afterEach(fn () => Cache::flush());

it('generates a longer code for a new short link than the old 6-character one', function () {
    $link = ShortLink::forDestination('https://hive.contractors/v/'.bin2hex(random_bytes(8)));

    expect(strlen($link->code))->toBe(10);
});

it('still resolves an existing 6-character short link', function () {
    $link = ShortLink::create([
        'code' => 'abc123',
        'destination' => 'https://hive.contractors/v/e124ae08566315b5',
    ]);

    $this->get('/l/'.$link->code)->assertRedirect('https://hive.contractors/v/e124ae08566315b5');
});

it('throttles repeated hits to a short link', function () {
    $link = ShortLink::create([
        'code' => 'thrtl1',
        'destination' => 'https://hive.contractors/v/e124ae08566315b5',
    ]);

    for ($i = 0; $i < 60; $i++) {
        $this->get('/l/'.$link->code)->assertRedirect();
    }

    $this->get('/l/'.$link->code)->assertStatus(429);
});

it('throttles the vendor and client schedule short-link routes', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->get('/v/0123456789abcdef');
    }

    $this->get('/v/0123456789abcdef')->assertStatus(429);

    for ($i = 0; $i < 60; $i++) {
        $this->get('/s/0123456789abcdef');
    }

    $this->get('/s/0123456789abcdef')->assertStatus(429);
});
