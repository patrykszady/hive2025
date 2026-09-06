<?php

namespace Tests\Feature;

use App\Services\CrewLeadEmailService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The triage rules that decide what never reaches the classifier.
 *
 * These are the cheap, certain exclusions, and the most valuable of them is
 * the direction filter: crew@ receives GS's OWN outbound client mail, and a
 * live sample showed 3 of the 5 most recent Inbox messages were sent from
 * support@hive.contractors. Without that rule every estimate and follow-up
 * the company sends manufactures a fake lead.
 */
class CrewLeadEmailServiceTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private function triage(array $overrides = []): ?string
    {
        $service = app(CrewLeadEmailService::class);

        $message = array_merge([
            'from' => [['email' => 'someone@example.com', 'name' => 'Someone']],
            'subject' => 'Kitchen remodel enquiry',
            'body' => 'We would like a quote for our kitchen.',
            'headers' => [],
        ], $overrides);

        $method = new \ReflectionMethod($service, 'triage');
        $method->setAccessible(true);

        return $method->invoke(
            $service,
            strtolower((string) ($message['from'][0]['email'] ?? '')),
            (string) $message['subject'],
            (string) $message['body'],
            $message,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('nylas.crew_leads.internal_domains', ['gs.construction', 'hive.contractors']);
    }

    public function test_a_genuine_enquiry_passes_triage(): void
    {
        $this->assertNull($this->triage());
    }

    public function test_our_own_outbound_mail_is_rejected(): void
    {
        foreach (['support@hive.contractors', 'crew@gs.construction', 'greg@gs.construction'] as $sender) {
            $this->assertSame(
                'internal',
                $this->triage(['from' => [['email' => $sender]]]),
                "{$sender} must not create a lead",
            );
        }
    }

    public function test_subdomains_of_internal_domains_are_rejected(): void
    {
        $this->assertSame('internal', $this->triage(['from' => [['email' => 'bot@mail.gs.construction']]]));
    }

    public function test_a_lookalike_domain_is_not_treated_as_internal(): void
    {
        // notgs.construction must NOT match gs.construction — a suffix test
        // without the dot boundary would let an impersonator through as
        // "internal" and silently drop their mail.
        $this->assertNull($this->triage(['from' => [['email' => 'someone@notgs.construction']]]));
    }

    public function test_noreply_senders_are_rejected(): void
    {
        foreach (['noreply@x.com', 'no-reply@x.com', 'mailer-daemon@x.com', 'postmaster@x.com'] as $sender) {
            $this->assertSame('automated', $this->triage(['from' => [['email' => $sender]]]));
        }
    }

    public function test_bulk_mail_headers_are_rejected(): void
    {
        // This is what let a marketing blast through before headers were
        // requested from Nylas at all.
        $this->assertSame('automated', $this->triage([
            'headers' => [['name' => 'List-Unsubscribe', 'value' => '<https://x.com/u>']],
        ]));

        $this->assertSame('automated', $this->triage([
            'headers' => [['name' => 'Precedence', 'value' => 'bulk']],
        ]));

        $this->assertSame('automated', $this->triage([
            'headers' => [['name' => 'Auto-Submitted', 'value' => 'auto-replied']],
        ]));
    }

    public function test_auto_submitted_no_is_a_normal_message(): void
    {
        // "Auto-Submitted: no" is what ordinary mail clients send.
        $this->assertNull($this->triage([
            'headers' => [['name' => 'Auto-Submitted', 'value' => 'no']],
        ]));
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $this->assertSame('empty', $this->triage(['subject' => '  ', 'body' => '']));
    }

    public function test_html_bodies_are_reduced_to_readable_text(): void
    {
        $service = app(CrewLeadEmailService::class);
        $method = new \ReflectionMethod($service, 'plainBody');
        $method->setAccessible(true);

        $text = $method->invoke($service, [
            'body' => '<div><style>p{color:red}</style><p>Basement remodel</p><br><p>in Gurnee &amp; nearby</p></div>',
        ]);

        $this->assertStringNotContainsString('<', $text);
        $this->assertStringNotContainsString('color:red', $text);
        $this->assertStringContainsString('Basement remodel', $text);
        $this->assertStringContainsString('Gurnee & nearby', $text);
    }

    public function test_dedupe_prefers_the_rfc_message_id(): void
    {
        $service = app(CrewLeadEmailService::class);
        $method = new \ReflectionMethod($service, 'externalId');
        $method->setAccessible(true);

        $withHeader = $method->invoke(
            $service,
            ['headers' => [['name' => 'Message-ID', 'value' => '<abc@mail.example.com>']]],
            ['nylas_message_id' => 'nylas-1'],
        );

        // Same mail read through a different grant gets a different Nylas id
        // but the same RFC id — and must dedupe to the same lead.
        $sameMailOtherGrant = $method->invoke(
            $service,
            ['headers' => [['name' => 'Message-ID', 'value' => ' <ABC@mail.example.com> ']]],
            ['nylas_message_id' => 'nylas-2'],
        );

        $this->assertSame($withHeader, $sameMailOtherGrant);
        $this->assertLessThanOrEqual(64, strlen($withHeader), 'must fit leads.external_id');

        // No header: fall back to the Nylas id, still hashed to fit.
        $withoutHeader = $method->invoke($service, ['headers' => []], ['nylas_message_id' => 'nylas-1']);
        $this->assertNotSame($withHeader, $withoutHeader);
        $this->assertLessThanOrEqual(64, strlen($withoutHeader));
    }

    public function test_replies_are_not_leads(): void
    {
        // Someone answering a consultation we sent is continuing an existing
        // conversation, not raising a new enquiry.
        $this->assertSame('reply', $this->triage([
            'headers' => [['name' => 'In-Reply-To', 'value' => '<abc@mail.example.com>']],
        ]));

        $this->assertSame('reply', $this->triage([
            'headers' => [['name' => 'References', 'value' => '<abc@mail.example.com>']],
        ]));

        // Header-less clients still announce it in the subject.
        foreach (['Re: GS Construction & Remodeling Consultation', 'RE: quote'] as $subject) {
            $this->assertSame('reply', $this->triage(['subject' => $subject]), $subject);
        }
    }

    public function test_a_forwarded_enquiry_is_not_a_reply(): void
    {
        // A homeowner who prepares one bid-request email and forwards it to
        // every contractor she found IS a fresh enquiry ("Fwd: Termite Repair
        // Bid Request" was skipped as a reply exactly this way). Forwards
        // carry References to the original in the SENDER'S mailbox, so
        // neither the prefix nor the headers may kill them — the classifier
        // judges their content instead.
        foreach (['Fwd: Termite Repair Bid Request', 'FW: plans', 'Tr: devis maison'] as $subject) {
            $this->assertNull($this->triage(['subject' => $subject]), $subject);
        }

        $this->assertNull($this->triage([
            'subject' => 'Fwd: Termite Repair Bid Request',
            'headers' => [['name' => 'References', 'value' => '<orig@mail.gmail.com>']],
        ]));
    }

    public function test_a_subject_merely_starting_with_re_is_still_a_lead(): void
    {
        // "Remodel" and "Renovation" begin with "re" — the colon is what makes
        // it a reply marker, and a naive prefix check would drop real enquiries.
        foreach (['Remodel quote for my kitchen', 'Renovation enquiry', 'Rec room build-out'] as $subject) {
            $this->assertNull($this->triage(['subject' => $subject]), $subject);
        }
    }

    public function test_a_sender_with_a_lead_on_file_is_filed_as_a_reply_not_a_new_lead(): void
    {
        $vendor = \App\Models\Vendor::factory()->create();
        $creator = \App\Models\User::query()->create([
            'first_name' => 'Crew', 'last_name' => 'Inbox', 'email' => 'crew-ingest@example.test',
            'cell_phone' => '7005550106', 'password' => bcrypt('password'), 'primary_vendor_id' => $vendor->id,
        ]);
        \App\Models\Lead::withoutGlobalScopes()->forceCreate([
            'belongs_to_vendor_id' => $vendor->id,
            'created_by_user_id' => $creator->id,
            'date' => now(),
            'origin' => 'Email',
            'external_source' => 'crew-email',
            'lead_data' => ['name' => 'J. Bradley Bates', 'email' => 'bates.jbradley@gmail.com'],
        ]);

        // No "Re:", no In-Reply-To — a fresh subject from a known sender.
        $this->assertSame('reply', $this->triage([
            'from' => [['email' => 'Bates.JBradley@gmail.com', 'name' => 'J. Bradley Bates']],
            'subject' => 'Bates Window order',
            'body' => 'Order looks complete. Questions - are the windows NEAT treated?',
        ]));
    }

    public function test_a_client_contact_is_not_a_prospect(): void
    {
        \App\Models\User::query()->create([
            'first_name' => 'Bonnie', 'last_name' => 'Bates', 'email' => 'bonnie.j.bates@gmail.com',
            'cell_phone' => '7005550105', 'password' => bcrypt('password'),
        ]);

        $this->assertSame('known_contact', $this->triage([
            'from' => [['email' => 'bonnie.j.bates@gmail.com', 'name' => 'Bonnie Bates']],
            'subject' => 'Window hardware',
            'body' => 'Champagne hardware on the dining room windows?',
        ]));
    }

    public function test_an_unknown_sender_still_reaches_the_classifier(): void
    {
        $this->assertNull($this->triage([
            'from' => [['email' => 'new.homeowner@example.com', 'name' => 'New Homeowner']],
        ]));
    }
}
