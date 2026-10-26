<?php

use App\Models\Client;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\LeadSenderRecogniser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Who an enquiry's sender already is to us: an account, a household writing
 * in together, or an address already in a project's email timeline
 * (Bliss Hansen, 2026-10-16).
 */

beforeEach(function () {
    config(['nylas.crew_leads.mailbox' => 'crew@gs.construction', 'nylas.crew_leads.internal_domains' => ['gs.construction', 'hive.contractors']]);
});

it('knows a sender by their account, whatever the letter case', function () {
    $user = User::query()->create(['first_name' => 'Bliss', 'last_name' => 'Hansen', 'email' => 'eblisshansen@gmail.com', 'cell_phone' => '2245550102']);

    $known = app(LeadSenderRecogniser::class)->recognise('EBlissHansen@gmail.com');

    expect($known['known'])->toBeTrue()->and($known['via'])->toBe('user')->and($known['user']->id)->toBe($user->id);
});

it('knows a household when a client contact is on copy, but links no account', function () {
    $trip = User::query()->create(['first_name' => 'Trip', 'last_name' => 'Finnegan', 'email' => 'trip.finnegan@gmail.com', 'cell_phone' => '2245550101']);
    Client::factory()->create()->users()->attach($trip->id);

    $known = app(LeadSenderRecogniser::class)->recognise('1118nhighlandave@gmail.com', ['crew@gs.construction', 'Trip.Finnegan@gmail.com']);

    expect($known)->toMatchArray(['known' => true, 'via' => 'household', 'user' => null]);
});

it('does not count our own addresses, or a user who is not a client contact, as a household', function () {
    User::query()->create(['first_name' => 'Greg', 'last_name' => 'S', 'email' => 'someone@example.test', 'cell_phone' => '2245550103']);

    expect(app(LeadSenderRecogniser::class)->recognise('new@example.test', ['crew@gs.construction', 'someone@example.test'])['known'])->toBeFalse();
});

it('knows an address already placed in the project email timeline', function () {
    $client = Client::factory()->create();
    EmailMessage::factory()->create(['from_email' => '1118nhighlandave@gmail.com', 'client_id' => $client->id]);
    EmailMessage::factory()->create(['from_email' => 'stranger@example.test']);

    expect(app(LeadSenderRecogniser::class)->recognise('1118nhighlandave@gmail.com'))->toMatchArray(['known' => true, 'via' => 'timeline'])
        ->and(app(LeadSenderRecogniser::class)->recognise('stranger@example.test')['known'])->toBeFalse('an unplaced message is not a relationship');
});

it('knows an address we wrote to on a project', function () {
    $client = Client::factory()->create();
    EmailMessage::factory()->outbound()->create(['from_email' => 'patryk@gs.construction', 'to' => ['1118nhighlandave@gmail.com'], 'client_id' => $client->id]);

    expect(app(LeadSenderRecogniser::class)->recognise('1118nhighlandave@gmail.com')['via'])->toBe('timeline');
});

it('treats everyone else as new', function () {
    expect(app(LeadSenderRecogniser::class)->recognise('new@example.test', ['partner@example.test']))->toMatchArray(['known' => false, 'via' => null, 'user' => null])
        ->and(app(LeadSenderRecogniser::class)->recognise('')['known'])->toBeFalse();
});
