<?php

namespace App\Support;

class IpNetworkMatcher
{
    /**
     * Determine whether a candidate IP belongs to the same "sender" network as a
     * reference IP.
     *
     * IPv6 addresses are compared on their /64 network prefix because residential
     * ISPs rotate the host portion (IPv6 privacy extensions) while keeping the /64
     * stable for the customer. IPv4 addresses are compared for exact equality to
     * avoid grouping unrelated subscribers behind a shared subnet.
     */
    public static function sameSenderNetwork(string $candidateIp, string $referenceIp): bool
    {
        $candidateIp = trim($candidateIp);
        $referenceIp = trim($referenceIp);

        if ($candidateIp === '' || $referenceIp === '') {
            return false;
        }

        if (strcasecmp($candidateIp, $referenceIp) === 0) {
            return true;
        }

        if (str_contains($candidateIp, ':') && str_contains($referenceIp, ':')) {
            $candidateNetwork = self::ipv6Network($candidateIp, 64);
            $referenceNetwork = self::ipv6Network($referenceIp, 64);

            return $candidateNetwork !== null
                && $referenceNetwork !== null
                && $candidateNetwork === $referenceNetwork;
        }

        return false;
    }

    /**
     * Determine whether an IP address falls within a CIDR range.
     * Supports both IPv4 (e.g. "203.0.113.0/24") and IPv6 (e.g. "2601:281:985:a50::/64").
     */
    public static function inCidr(string $ip, string $cidr): bool
    {
        $ip = trim($ip);
        $cidr = trim($cidr);

        if ($ip === '' || $cidr === '') {
            return false;
        }

        if (! str_contains($cidr, '/')) {
            return strcasecmp($ip, $cidr) === 0;
        }

        [$subnet, $prefix] = explode('/', $cidr, 2);

        if (! is_numeric($prefix)) {
            return false;
        }

        $prefix = (int) $prefix;

        $ipPacked = @inet_pton($ip);
        $subnetPacked = @inet_pton(trim($subnet));

        if ($ipPacked === false || $subnetPacked === false) {
            return false;
        }

        if (strlen($ipPacked) !== strlen($subnetPacked)) {
            return false;
        }

        $maxBits = strlen($ipPacked) * 8;

        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        return self::maskPacked($ipPacked, $prefix) === self::maskPacked($subnetPacked, $prefix);
    }

    /**
     * Return the canonical IPv6 network address for the given prefix length,
     * or null when the value is not a valid IPv6 address.
     */
    public static function ipv6Network(string $ip, int $prefix): ?string
    {
        $packed = @inet_pton(trim($ip));

        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        if ($prefix < 0 || $prefix > 128) {
            return null;
        }

        $network = inet_ntop(self::maskPacked($packed, $prefix));

        return $network === false ? null : $network;
    }

    /**
     * Apply a network prefix mask to a packed in_addr / in6_addr binary string.
     */
    protected static function maskPacked(string $packed, int $prefix): string
    {
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        $masked = substr($packed, 0, $fullBytes);

        if ($remainingBits > 0 && $fullBytes < strlen($packed)) {
            $mask = chr((0xFF << (8 - $remainingBits)) & 0xFF);
            $masked .= ($packed[$fullBytes] & $mask);
            $fullBytes++;
        }

        return str_pad($masked, strlen($packed), "\0");
    }
}
