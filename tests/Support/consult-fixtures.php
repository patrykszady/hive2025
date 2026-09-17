<?php

/**
 * Consult-booking fixture shared by LeadConsultBookingTest and
 * LeadFeedbackTest. It lives here, loaded from tests/Pest.php, because a
 * helper defined inside one test file only exists in the worker process that
 * happened to load that file — fine single-process, "undefined function" in
 * the parallel run.
 */

use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;

function makeConsultFixture(?array $slot = null): array
{
    $slot ??= ['date' => now()->addDays(2)->format('Y-m-d'), 'time' => '1-3 PM'];
    config(['email_tracking.provider' => 'mailtrap']);

    $vendor = Vendor::factory()->create(['options' => ['short_name' => 'GSC']]);

    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Sender',
        'email' => 'consult-admin.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);

    $contact = User::query()->create([
        'first_name' => 'Preet',
        'last_name' => 'Singh',
        'email' => 'preet.'.uniqid().'@example.com',
        'cell_phone' => fake()->unique()->numerify('224888####'),
    ]);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $client->users()->attach($contact->id);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'gs.construction',
        'user_id' => $contact->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $contact->id,
        'lead_data' => [
            'name' => 'Preet Kanwal Singh',
            'address' => '123 Main St, Palatine, IL 60067',
            'message' => 'Kitchen remodel',
            'email' => $contact->email,
            'availability' => [$slot],
        ],
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    return compact('vendor', 'admin', 'contact', 'client', 'lead');
}
