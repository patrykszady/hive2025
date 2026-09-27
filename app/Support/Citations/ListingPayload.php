<?php

namespace App\Support\Citations;

use Illuminate\Support\Str;

/**
 * Adapted from gsc's/dawnsellshomes' app/Support/Citations/
 * ListingPayload.php for a software company rather than a contractor or a
 * real-estate team: identity, contact, address, categories, services,
 * descriptions in three lengths, and the logo. Built entirely from
 * config/app.php, config/mail.php and config/marketing.php's own area
 * labels — nothing here is invented. This app has no legal name, founding
 * year, published office hours or product-screenshot library, so those
 * fields are omitted/empty rather than fabricated, the same restraint
 * dawnsellshomes' version documents for its own missing fields. There is
 * also no public customer-count figure on the marketing site to cite
 * (grepped resources/views/welcome.blade.php), so `stats` stays empty
 * rather than guessing a number.
 */
class ListingPayload
{
    public static function make(): array
    {
        $name = (string) config('app.name', 'Hive Contractors');
        $address = self::parseAddress((string) config('app.physical_address', ''));
        $services = self::services();

        $short = Str::limit(trim(sprintf(
            '%s is the CRM and project-management software built for small contractors and subcontractors.', $name
        )), 160, '');
        $medium = trim(sprintf(
            '%s is project-management and CRM software for small contractors and subcontractors, covering %s — all in one place.',
            $name, self::joinAllButLast($services)
        ));
        $long = trim($medium.' Services: '.implode(', ', $services).'.');

        return [
            'name' => $name,
            'short_name' => 'Hive',
            'legal_name' => '',
            'also_known_as' => '',
            'contact' => [
                'first_name' => '',
                'last_name' => '',
                'title' => 'Support',
                'owners' => '',
            ],
            'phone' => '',
            'phone_digits' => '',
            'email' => (string) config('mail.from.address', ''),
            'website' => rtrim((string) config('app.marketing_url', config('app.url')), '/').'/',
            'address' => $address + ['full' => (string) config('app.physical_address', '')],
            'hours' => [],
            'hours_text' => '',
            'founded' => null,
            'languages' => ['English'],
            'categories' => ['Construction management software', 'Field service management software', 'CRM software'],
            'services' => $services,
            'service_areas' => [], // a nationwide SaaS product, not a service-area business
            'description' => ['short' => $short, 'medium' => $medium, 'long' => $long],
            'stats' => [],
            'social' => self::socialLinks(),
            'profiles' => self::profiles(),
            'logo' => [
                'svg' => asset('images/hive-mark.svg'),
                // No separate PNG rendition exists — the SVG mark doubles for both slots.
                'png' => asset('images/hive-mark.svg'),
            ],
            'photos' => self::photos(),
        ];
    }

    /**
     * The product's own areas (config/marketing.php's public feature
     * pages), in their configured order — a real list of what the
     * product does, not an invented one.
     *
     * @return list<string>
     */
    protected static function services(): array
    {
        return collect((array) config('marketing.areas', []))
            ->map(fn (array $area) => (string) ($area['label'] ?? ''))
            ->filter()
            ->values()
            ->all();
    }

    protected static function joinAllButLast(array $items): string
    {
        $items = array_values($items);
        if ($items === []) {
            return '';
        }
        if (count($items) === 1) {
            return $items[0];
        }
        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    /**
     * There is no `socials.url.*` platform-setting write path outside a
     * request (PlatformSetting reads the database), so this reads the
     * same store the Social Media screen writes.
     */
    protected static function socialLinks(): array
    {
        return array_filter([
            'facebook' => (string) (\App\Models\PlatformSetting::get('socials.url.facebook') ?? ''),
            'instagram' => (string) (\App\Models\PlatformSetting::get('socials.url.instagram') ?? ''),
            'linkedin' => (string) (\App\Models\PlatformSetting::get('socials.url.linkedin') ?? ''),
        ]);
    }

    /** Every saved social profile, label => url, for whichever directory form wants the full set. */
    protected static function profiles(): array
    {
        $out = [];
        foreach ((array) config('social-platforms', []) as $key => $platform) {
            $url = (string) (\App\Models\PlatformSetting::get('socials.url.'.$key) ?? '');
            if ($url !== '') {
                $out[(string) ($platform['label'] ?? $key)] = $url;
            }
        }

        return $out;
    }

    /**
     * No product-screenshot library exists as a first-class asset this
     * class can draw from (unlike gsc/dawnsellshomes' project photos or
     * team headshots) — see this class's docblock. Empty rather than
     * pointing at arbitrary marketing images that were never meant to be
     * a "best photos" gallery.
     *
     * @return list<array{url: string, caption: string, project: string, project_url: string}>
     */
    public static function photos(): array
    {
        return [];
    }

    /**
     * Best-effort split of config/app.php's single-string physical
     * address ("305 S Ridge St, PO Box 1504, Breckenridge, CO 80424")
     * into the street/city/state/zip fields a directory form actually
     * asks for. Falls back to putting the whole string in `street` when
     * the shape doesn't match (never silently drops the address).
     *
     * @return array{street: string, city: string, state: string, state_name: string, zip: string, country: string}
     */
    public static function parseAddress(string $address): array
    {
        $address = trim($address);
        if ($address === '') {
            return ['street' => '', 'city' => '', 'state' => '', 'state_name' => '', 'zip' => '', 'country' => 'US'];
        }

        $parts = array_map('trim', explode(',', $address));
        $last = array_pop($parts);

        if ($last !== null && preg_match('/^(?<state>[A-Z]{2})\s+(?<zip>\d{5})(-\d{4})?$/', $last, $m)) {
            $city = $parts !== [] ? (string) array_pop($parts) : '';
            $street = implode(', ', $parts);
            $states = ['CO' => 'Colorado', 'IL' => 'Illinois'];

            return [
                'street' => $street, 'city' => $city, 'state' => $m['state'],
                'state_name' => $states[$m['state']] ?? $m['state'], 'zip' => $m['zip'], 'country' => 'US',
            ];
        }

        return ['street' => $address, 'city' => '', 'state' => '', 'state_name' => '', 'zip' => '', 'country' => 'US'];
    }
}
