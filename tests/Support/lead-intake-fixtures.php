<?php

/**
 * Shared fixtures for the leads API tests: an API user of a company with a
 * connected mailbox, and the payload gs.construction sends for an email
 * enquiry it read.
 */

use App\Models\CompanyEmail;
use App\Models\User;
use App\Models\Vendor;
use Laravel\Sanctum\Sanctum;

if (function_exists('emailIntakeFixture')) {
    return;
}

function emailIntakeFixture(): array
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    config(['nylas.crew_leads.vendor_id' => $vendor->id, 'services.gsc.url' => 'https://gs.test']);

    $api = new User();
    $api->forceFill([
        'first_name' => 'Site', 'last_name' => 'Bot',
        'email' => 'site-bot.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $api->save();
    $vendor->users()->attach($api->id, ['role_id' => 1]);

    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => 'crew@gs.construction', 'grant_id' => 'grant-crew']);

    Sanctum::actingAs($api);

    return compact('vendor', 'api');
}

function emailLeadPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'external_id' => sha1('<caj1234@mail.gmail.com>'),
        'source' => 'crew-email',
        'name' => 'William Johnson',
        'email' => 'willjohn1089@example.test',
        'address' => '7815 Kenton Ave',
        'city' => 'Skokie',
        'state' => 'IL',
        'zip' => '60076',
        'subject' => 'Bathroom remodel',
        'message' => 'Hi, we would like to remodel the hall bathroom at 7815 Kenton Ave, Skokie. Attached is the plan. Thanks, Will',
        'submitted_at' => '2026-09-15T20:20:00Z',
        'attachments' => [['url' => 'https://gs.test/storage/email-leads/9/plan.pdf', 'name' => 'plan.pdf', 'mime' => 'application/pdf', 'size' => 4]],
        'extracted' => ['mailbox' => 'crew@gs.construction', 'project_type' => 'Bathroom remodel', 'scope_summary' => 'Remodel the hall bathroom.', 'cc_emails' => ['partner@example.test'], 'is_lead' => true, 'confidence' => 0.96, 'reason' => 'Homeowner enquiry'],
        'in_reply_to' => '<caj1234@mail.gmail.com>',
    ], $overrides);
}

