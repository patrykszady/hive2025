<?php

namespace App\Support\Seo\Reports;

use SsSystems\Platform\Reports\Contracts\SiteIdentity;

/**
 * SiteIdentity over config/brand.php. Ported from dawnsellshomes'
 * ConfigSiteIdentity, adjusted for this site's config keys.
 *
 * expectedPhone()/expectedAddress() are both null — hive.contractors is a
 * SaaS marketing site with no public phone number or address, so
 * seo:gbp-parity's NAP check has nothing to compare and skips cleanly (see
 * SiteIdentity::expectedPhone()'s docblock: null means "the phone-parity
 * check is then skipped, exactly like today").
 *
 * gbpServices() is always [] — there is no Google Business Profile / local
 * services catalog behind a SaaS product, so the service-parity half of
 * seo:gbp-parity simply has nothing to compare either, same reasoning as
 * dawnsellshomes' identical method.
 */
class ConfigSiteIdentity implements SiteIdentity
{
    public function expectedPhone(): ?string
    {
        $phone = trim((string) config('brand.phone', ''));

        return $phone !== '' ? $phone : null;
    }

    public function expectedAddress(): ?string
    {
        $address = trim((string) config('brand.address', ''));

        return $address !== '' ? $address : null;
    }

    public function gbpServices(): array
    {
        return [];
    }

    public function gbpServiceSlugAliases(): array
    {
        return [];
    }
}
