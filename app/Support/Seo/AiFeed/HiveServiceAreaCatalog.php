<?php

namespace App\Support\Seo\AiFeed;

use SsSystems\Platform\Seo\AiFeed\Contracts\ServiceAreaCatalog;

/**
 * This site's `service_area_matrix` for /ai-feed.json. hive.contractors
 * is a nationwide software product, not a service-area business (see
 * App\Support\Citations\ListingPayload's empty `service_areas` and
 * App\Support\Seo\HiveSeoProfile's empty `towns()`), so the matrix is one
 * row — the United States — with the team's Chicago-area base from
 * config('geo-answers.meta.base') and every product area's English page
 * under it, the way gs.construction's rows carry a suburb's per-service
 * URLs. `AiFeedController` passes the flat `service_area` list through
 * `AiFeedBuilder`'s `$extra`, as that site does.
 */
final class HiveServiceAreaCatalog implements ServiceAreaCatalog
{
    public function __construct(private readonly HiveServiceCatalog $services = new HiveServiceCatalog) {}

    public function serviceAreaMatrix(): array
    {
        $services = [];

        foreach ($this->services->services() as $service) {
            $services[$service['slug']] = $service['url'];
        }

        return [[
            'name' => (string) config('geo-answers.meta.service_area', 'United States'),
            'country' => 'US',
            'coverage' => 'nationwide',
            'team_base' => config('geo-answers.meta.base'),
            'url' => marketing_url('en/welcome'),
            'services' => $services,
        ]];
    }
}
