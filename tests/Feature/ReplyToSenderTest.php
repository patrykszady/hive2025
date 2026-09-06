<?php

use App\Models\User;
use App\Models\Vendor;
use App\Support\MailActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Mime\Email;

uses(RefreshDatabase::class);

/**
 * Mail sent from the company inbox (crew@) on someone's behalf: "Reply"
 * must reach that person, and the inbox Hive ingests must still get it.
 */
function replyToActor(): User
{
    $vendor = Vendor::query()->create([
        'business_name' => 'GS Construction', 'business_type' => 'Sub', 'business_email' => 'crew@gs.test',
        'address' => '123 Main St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $user = User::query()->create([
        'first_name' => 'Patryk', 'last_name' => 'Szady', 'email' => 'patryk@gs.test',
        'cell_phone' => '7005550104', 'password' => bcrypt('password'),
    ]);
    $user->forceFill(['primary_vendor_id' => $vendor->id])->saveQuietly();

    return $user->fresh();
}

function replyToAddresses(Email $email): array
{
    return array_map(fn ($a) => $a->getAddress(), $email->getReplyTo());
}

afterEach(fn () => MailActor::forget());

it('puts the sender first and keeps the company inbox when mail goes out as crew@', function () {
    MailActor::as(replyToActor());

    $email = (new Email())->from('crew@gs.test')->replyTo('crew@gs.test')->to('client@example.com')->subject('Estimate')->text('Body');
    Event::until(new MessageSending($email));

    expect(replyToAddresses($email))->toBe(['patryk@gs.test', 'crew@gs.test'])
        ->and($email->getReplyTo()[0]->getName())->toBe('Patryk Szady');
});

it('adds the sender when the crew@ mail carried no reply-to at all', function () {
    MailActor::as(replyToActor());

    $email = (new Email())->from('Crew@GS.test')->to('client@example.com')->subject('Hi')->text('Body');
    Event::until(new MessageSending($email));

    expect(replyToAddresses($email))->toBe(['patryk@gs.test', 'crew@gs.test']);
});

it('uses the signed-in user when no job named a sender', function () {
    $this->actingAs(replyToActor());

    $email = (new Email())->from('crew@gs.test')->to('client@example.com')->subject('Hi')->text('Body');
    Event::until(new MessageSending($email));

    expect(replyToAddresses($email))->toBe(['patryk@gs.test', 'crew@gs.test']);
});

it('leaves mail alone when nobody is acting, when it is already from the person, or when reply-to points elsewhere', function () {
    $user = replyToActor();

    $nobody = (new Email())->from('crew@gs.test')->replyTo('crew@gs.test')->to('c@example.com')->subject('x')->text('b');
    Event::until(new MessageSending($nobody));
    expect(replyToAddresses($nobody))->toBe(['crew@gs.test']);

    MailActor::as($user);

    $ownAddress = (new Email())->from('patryk@gs.test')->to('c@example.com')->subject('x')->text('b');
    Event::until(new MessageSending($ownAddress));
    expect(replyToAddresses($ownAddress))->toBe([]);

    $explicit = (new Email())->from('crew@gs.test')->replyTo('lead-replies@other.test')->to('c@example.com')->subject('x')->text('b');
    Event::until(new MessageSending($explicit));
    expect(replyToAddresses($explicit))->toBe(['lead-replies@other.test']);

    $systemMail = (new Email())->from('support@hive.test')->to('c@example.com')->subject('x')->text('b');
    Event::until(new MessageSending($systemMail));
    expect(replyToAddresses($systemMail))->toBe([]);
});
