<?php

use App\Jobs\SendLeadReplyJob;
use App\Models\CompanyEmail;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function consultInviteFixture(): array
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Szady',
        'email' => 'invite.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    CompanyEmail::withoutEvents(fn () => CompanyEmail::create([
        'email' => 'support@example.test', 'vendor_id' => $vendor->id, 'grant_id' => 'grant-1',
    ]));

    $lead = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'lead_data' => ['name' => 'Kristin White', 'email' => 'kristin@example.test', 'phone' => '7606853015'],
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]));

    return ['vendor' => $vendor, 'user' => $user, 'lead' => $lead];
}

it('sends the consult invite in one click, asking the homeowner to pick times', function () {
    Queue::fake();
    $fx = consultInviteFixture();

    EmailTemplate::create([
        'vendor_id' => $fx['vendor']->id,
        'type' => 'lead',
        'name' => 'Consult',
        'subject' => '{{vendor_name}} Consultation | {{client_last_names}}',
        'body' => '<p>Hi {{client_first_name}},</p><p>{{lead_intro}}</p><p>{{lead_time_block}}</p>',
    ]);

    Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Leads\LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->set('from', 'support@example.test')
        ->set('to', ['kristin@example.test'])
        ->call('sendConsultInvite');

    Queue::assertPushed(SendLeadReplyJob::class, function (SendLeadReplyJob $job) {
        // The job's constructor promotes protected properties — read them
        // through a bound closure rather than loosening the job for a test.
        $seen = (fn () => [
            'subject' => $this->subject,
            'body' => $this->body,
            'template' => $this->emailTemplateName,
            'recipients' => $this->recipients,
        ])->call($job);

        return str_contains($seen['subject'], 'Consultation')
            && str_contains($seen['body'], 'select new consultation times')
            && str_contains($seen['body'], 'Hi Kristin')
            && $seen['template'] === 'Consult'
            && $seen['recipients'] === ['kristin@example.test'];
    });
});

it('refuses gracefully when no Consult template exists', function () {
    Queue::fake();
    $fx = consultInviteFixture();

    Livewire::actingAs($fx['user'])
        ->test(\App\Livewire\Leads\LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->set('from', 'support@example.test')
        ->set('to', ['kristin@example.test'])
        ->call('sendConsultInvite');

    Queue::assertNotPushed(SendLeadReplyJob::class);
});
