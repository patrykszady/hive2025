<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

/**
 * The "Consult" reply template for the lead Messages tab. First (and default)
 * template of type "lead": booking an availability slot renders it via
 * {{lead_availability}}, and sending with a slot selected creates the client's
 * project + the "{{short_vendor_name}}/{{client_last_names}} Consult" Meet
 * task (LeadCreate::bookConsult).
 *
 * Idempotent — run on deploy: php artisan db:seed --class=LeadConsultTemplateSeeder
 */
class LeadConsultTemplateSeeder extends Seeder
{
    public function run(): void
    {
        EmailTemplate::updateOrCreate(
            ['type' => 'lead', 'name' => 'Consult'],
            [
                'vendor_id' => 1,
                'subject' => '{{vendor_name}} Consultation | {{client_last_names}}',
                'body' => '<p>Hi {{client_first_name}},</p>'
                    .'<p></p>'
                    // {{lead_intro}}: greets a first contact, or thanks them for
                    // rescheduling once they've sent new times via the picker.
                    .'<p>{{lead_intro}}</p>'
                    .'<p></p>'
                    // {{lead_time_block}}: the confirm-line + slot when a shared
                    // time is still bookable, or the signed pick-new-times link
                    // when they've all passed / none were given.
                    .'<p>{{lead_time_block}}</p>'
                    .'<p></p>'
                    .'<p>Thank you for considering us for your project,<br>'
                    .'{{short_vendor_name}}<br>'
                    .'Greg &amp; Patryk | (224) 735-4200<br>'
                    // Same links the estimate templates use in their footers.
                    .'<a href="https://www.gs.construction">www.gs.construction</a>'
                    .' | <a href="https://www.google.com/search?sca_esv=25ed468a7ca66eef&amp;sxsrf=AE3TifOoLy3ZK-tf1GforAcFxLMvpg-10A:1763779038590&amp;q=www.gs.construction&amp;si=AMgyJEvWrqMtbdpM6zU9DoVHqM7BZVYVJqG6zLTeueLph2SDZZJ-_49tBx3xCMmoJtP1yZVsDm49UzGsIl1RL196h6P0M89Y6ApsniC7JsuiI1fRPLZzlFgDgIhf3y9lsIW4hpqzxSmAUpeg99wk9SB7c9SfyeHd7P5ecjiH-JG2d7eGBAil5eM%3D&amp;sa=X&amp;ved=2ahUKEwjWv7r43ISRAxX_GzQIHbSjKCEQ6RN6BAgQEAE&amp;biw=1496&amp;bih=877&amp;dpr=1.5">Google</a>'
                    .' | <a href="https://www.houzz.com/pro/gs-construction">Best of Houzz</a>'
                    .' | <a href="https://www.instagram.com/gs.construction.co/">Instagram</a></p>',
            ],
        );
    }
}
