<?php

/**
 * Katie Love's grout email and the world around it, shared by the
 * service-call tests. require_once this file rather than declaring these
 * names twice.
 */

use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Http;

if (! function_exists('serviceCallWorld')) {
    /**
     * @return array{vendor: Vendor, admin: User, mailbox: string, client: Client, katie: User, jon: User, project: Project}
     */
    function serviceCallWorld(string $projectStatus = 'complete', ?Vendor $vendor = null): array
    {
        // The app API admits GS Construction's own team only (vendor 1):
        // app tests pass that vendor in.
        $vendor ??= sec3_makeVendor('GS');
        $admin = sec3_makeAdmin($vendor, 'office');
        $mailbox = 'patryk.'.uniqid().'@gs.construction';
        $admin->forceFill(['email' => $mailbox])->save();
        CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $mailbox, 'grant_id' => 'grant-1']);

        $client = sec3_makeClient($vendor);
        $katie = sec3_makeClientUser($client, 'katie');
        $katie->forceFill(['first_name' => 'Katie', 'last_name' => 'Love'])->save();
        $jon = sec3_makeClientUser($client, 'jon');

        $project = sec3_makeProject($vendor, $client, ['project_name' => 'Bathrooms & Basement', 'address' => '415 Lincoln Ave']);
        ProjectStatus::create([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $vendor->id,
            'status_code' => $projectStatus === 'active' ? 6 : 7,
            'start_date' => now()->subMonths(6)->format('Y-m-d'),
        ]);

        $cancelled = sec3_makeProject($vendor, $client, ['project_name' => 'Basement Repairs', 'address' => '415 Lincoln Ave']);
        ProjectStatus::create([
            'project_id' => $cancelled->id,
            'belongs_to_vendor_id' => $vendor->id,
            'status_code' => 10,
            'start_date' => now()->subMonths(9)->format('Y-m-d'),
        ]);

        return compact('vendor', 'admin', 'mailbox', 'client', 'katie', 'jon', 'project');
    }

    function fakeServiceCallVerdict(array $overrides = [], int $status = 200): void
    {
        Http::fake(['api.openai.com/*' => Http::response($status === 200 ? [
            'choices' => [['message' => ['content' => json_encode(array_merge([
                'is_service_request' => true,
                'confidence' => 0.96,
                'project_id' => null,
                'task_title' => 'Seal cracked grout in both bathrooms',
                'issue' => 'the cracked grout in both bathrooms',
                'reason' => 'Reports grout cracking in bathrooms GS remodeled.',
            ], $overrides))]]],
        ] : ['error' => 'down'], $status)]);
    }

    /** Katie's real email, as the mail readers hand it over. */
    function katieEmail(array $world, array $overrides = []): array
    {
        return array_merge([
            'message_key' => sha1('<katie-'.uniqid().'@mail.gmail.com>'),
            'nylas_message_id' => 'nylas-'.uniqid(),
            'thread_id' => 'thread-'.uniqid(),
            'mailbox' => $world['mailbox'],
            'from_email' => $world['katie']->email,
            'from_name' => 'Katie Love',
            'to' => [$world['mailbox']],
            'cc' => [$world['jon']->email],
            'subject' => 'Grout',
            'body' => "Hi Greg and Patryk! I hope you're well! We are still loving our bathrooms. I have officially noticed grout cracking in both bathrooms -- could we find a time for y'all to come seal those up? Thank you! Katie",
            'rfc_message_id' => '<katie-original@mail.gmail.com>',
            'received_at' => now()->subMinutes(10),
        ], $overrides);
    }
}
