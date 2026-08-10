<?php

namespace App\Support;

/**
 * The one company sign-off — the same block the Consult and estimate
 * templates end with, for every email built in code (follow-ups, automatic
 * asks). One source so nobody's footer drifts.
 */
class EmailSignature
{
    public static function html(?string $shortVendorName = null): string
    {
        $name = trim((string) ($shortVendorName ?: 'GS Construction'));

        return '<p>Thank you,<br>'
            .e($name).'<br>'
            .'Greg &amp; Patryk | (224) 735-4200<br>'
            .'<a href="https://www.gs.construction">www.gs.construction</a>'
            .' | <a href="https://www.google.com/search?sca_esv=25ed468a7ca66eef&amp;sxsrf=AE3TifOoLy3ZK-tf1GforAcFxLMvpg-10A:1763779038590&amp;q=www.gs.construction&amp;si=AMgyJEvWrqMtbdpM6zU9DoVHqM7BZVYVJqG6zLTeueLph2SDZZJ-_49tBx3xCMmoJtP1yZVsDm49UzGsIl1RL196h6P0M89Y6ApsniC7JsuiI1fRPLZzlFgDgIhf3y9lsIW4hpqzxSmAUpeg99wk9SB7c9SfyeHd7P5ecjiH-JG2d7eGBAil5eM%3D&amp;sa=X&amp;ved=2ahUKEwjWv7r43ISRAxX_GzQIHbSjKCEQ6RN6BAgQEAE&amp;biw=1496&amp;bih=877&amp;dpr=1.5">Google</a>'
            .' | <a href="https://www.houzz.com/pro/gs-construction">Best of Houzz</a>'
            .' | <a href="https://www.instagram.com/gs.construction.co/">Instagram</a></p>';
    }
}
