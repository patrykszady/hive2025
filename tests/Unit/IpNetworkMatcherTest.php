<?php

use App\Support\IpNetworkMatcher;

it('matches identical IPv4 addresses', function () {
    expect(IpNetworkMatcher::sameSenderNetwork('203.0.113.4', '203.0.113.4'))->toBeTrue();
});

it('does not group different IPv4 addresses in the same subnet', function () {
    expect(IpNetworkMatcher::sameSenderNetwork('203.0.113.4', '203.0.113.9'))->toBeFalse();
});

it('matches IPv6 addresses sharing the same /64 network', function () {
    expect(IpNetworkMatcher::sameSenderNetwork(
        '2601:281:985:a50:4d00:e248:f0ab:724c',
        '2601:281:985:a50:2c11:20a9:1175:dfb',
    ))->toBeTrue();
});

it('does not match IPv6 addresses in different /64 networks', function () {
    expect(IpNetworkMatcher::sameSenderNetwork(
        '2601:281:985:a50:4d00:e248:f0ab:724c',
        '2601:281:97c:1ad0:4871:1d66:15cb:864a',
    ))->toBeFalse();
});

it('does not cross-match IPv4 and IPv6 addresses', function () {
    expect(IpNetworkMatcher::sameSenderNetwork('203.0.113.4', '2601:281:985:a50::1'))->toBeFalse();
});

it('returns false for empty values', function () {
    expect(IpNetworkMatcher::sameSenderNetwork('', '203.0.113.4'))->toBeFalse();
    expect(IpNetworkMatcher::sameSenderNetwork('203.0.113.4', ''))->toBeFalse();
});

it('matches IPv6 addresses inside a CIDR range', function () {
    expect(IpNetworkMatcher::inCidr('2601:281:985:a50:4d00:e248:f0ab:724c', '2601:281:985:a50::/64'))->toBeTrue();
    expect(IpNetworkMatcher::inCidr('2601:281:97c:1ad0::5', '2601:281:985:a50::/64'))->toBeFalse();
});

it('matches IPv4 addresses inside a CIDR range', function () {
    expect(IpNetworkMatcher::inCidr('203.0.113.55', '203.0.113.0/24'))->toBeTrue();
    expect(IpNetworkMatcher::inCidr('203.0.114.55', '203.0.113.0/24'))->toBeFalse();
});

it('computes the canonical IPv6 /64 network', function () {
    expect(IpNetworkMatcher::ipv6Network('2601:281:985:a50:4d00:e248:f0ab:724c', 64))
        ->toBe('2601:281:985:a50::');
    expect(IpNetworkMatcher::ipv6Network('not-an-ip', 64))->toBeNull();
});
