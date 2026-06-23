<?php

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    config(['mail.suppressed_recipients' => ['support@hive.contractors']]);
});

it('strips a suppressed address but keeps valid recipients', function () {
    $email = (new Email())
        ->from('sender@example.com')
        ->to('client@example.com', 'support@hive.contractors')
        ->cc('owner@example.com')
        ->subject('Estimate')
        ->text('Body');

    $result = Event::until(new MessageSending($email));

    expect($result)->toBeNull();

    $to = array_map(fn ($address) => $address->getAddress(), $email->getTo());
    $cc = array_map(fn ($address) => $address->getAddress(), $email->getCc());

    expect($to)->toBe(['client@example.com']);
    expect($cc)->toBe(['owner@example.com']);
});

it('matches suppressed addresses case-insensitively', function () {
    $email = (new Email())
        ->from('sender@example.com')
        ->to('client@example.com', 'Support@Hive.Contractors')
        ->subject('Estimate')
        ->text('Body');

    Event::until(new MessageSending($email));

    $to = array_map(fn ($address) => $address->getAddress(), $email->getTo());

    expect($to)->toBe(['client@example.com']);
});

it('cancels the send when only suppressed addresses remain', function () {
    $email = (new Email())
        ->from('sender@example.com')
        ->to('support@hive.contractors')
        ->subject('Estimate')
        ->text('Body');

    $result = Event::until(new MessageSending($email));

    expect($result)->toBeFalse();
    expect($email->getTo())->toBeEmpty();
});
