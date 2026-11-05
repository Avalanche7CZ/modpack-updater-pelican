<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Security;

use RuntimeException;

final class PublicAddress
{
    public static function resolve(string $host): string
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('Secure provider requests require the PHP cURL extension');
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (! $records) {
            throw new RuntimeException('Provider hostname cannot be resolved');
        }
        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null) {
                continue;
            }
            if (! self::isPublic($ip)) {
                throw new RuntimeException('Provider hostname resolved to a non-public address');
            }
            $addresses[] = $ip;
        }
        if (! $addresses) {
            throw new RuntimeException('Provider hostname has no public address');
        }

        return $addresses[0];
    }

    public static function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($ip, ':')) {
            $bytes = inet_pton($ip);

            // Only global unicast 2000::/3; reject documentation ranges and mapped IPv4.
            return $bytes !== false && (ord($bytes[0]) & 0xE0) === 0x20 && ! str_starts_with(strtolower($ip), '2001:db8:');
        }
        $parts = array_map('intval', explode('.', $ip));

        return $parts[0] < 224 && ! ($parts[0] === 192 && $parts[1] === 88 && $parts[2] === 99) && ! ($parts[0] === 100 && $parts[1] >= 64 && $parts[1] <= 127) &&
            ! ($parts[0] === 192 && $parts[1] === 0 && in_array($parts[2], [0, 2], true)) &&
            ! ($parts[0] === 198 && (in_array($parts[1], [18, 19], true) || ($parts[1] === 51 && $parts[2] === 100))) &&
            ! ($parts[0] === 203 && $parts[1] === 0 && $parts[2] === 113);
    }
}
