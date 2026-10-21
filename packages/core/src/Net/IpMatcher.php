<?php

declare(strict_types=1);

namespace Stero\Shield\Net;

/** Matches exact IPs and CIDRs using packed address bytes. Invalid configuration fails at boot. */
final class IpMatcher
{
    /** @var list<array{bytes: list<int>, prefix: int}> */
    private array $networks = [];

    /** @param list<string> $entries */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            if (!mb_check_encoding($entry, 'UTF-8')) {
                throw new \InvalidArgumentException('Invalid IP allowlist entry');
            }
            $slash = mb_strpos($entry, '/', 0, 'UTF-8');
            $address = $slash === false ? $entry : mb_substr($entry, 0, $slash, 'UTF-8');
            $bytes = self::bytes($address);
            if ($bytes === null) {
                throw new \InvalidArgumentException('Invalid IP allowlist entry');
            }
            $mapped = self::isMapped($bytes);
            $maximum = count($bytes) * 8;
            $prefix = $maximum;
            if ($slash !== false) {
                $text = mb_substr($entry, $slash + 1, null, 'UTF-8');
                if ($text === '' || !ctype_digit($text)) {
                    throw new \InvalidArgumentException('Invalid CIDR prefix');
                }
                // Compare before casting so an oversized integer cannot overflow.
                if (mb_strlen($text, 'UTF-8') > 3 || (int) $text > $maximum) {
                    throw new \InvalidArgumentException('Invalid CIDR prefix');
                }
                $prefix = (int) $text;
            }
            if ($mapped) {
                if ($prefix < 96) {
                    throw new \InvalidArgumentException('Mapped IPv6 CIDRs require a prefix of at least 96');
                }
                $bytes = array_slice($bytes, 12);
                $prefix -= 96;
            }
            $this->networks[] = ['bytes' => $bytes, 'prefix' => $prefix];
        }
    }

    public function matches(string $ip): bool
    {
        try {
            $bytes = self::bytes($ip);
            if ($bytes === null) {
                return false;
            }
            if (self::isMapped($bytes)) {
                $bytes = array_slice($bytes, 12);
            }
            foreach ($this->networks as $network) {
                if (count($bytes) !== count($network['bytes'])) {
                    continue;
                }
                $remaining = $network['prefix'];
                foreach ($bytes as $index => $byte) {
                    if ($remaining === 0) {
                        break;
                    }
                    $bits = min(8, $remaining);
                    $mask = (255 << (8 - $bits)) & 255;
                    if (($byte & $mask) !== ($network['bytes'][$index] & $mask)) {
                        continue 2;
                    }
                    $remaining -= $bits;
                }
                return true;
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    /** @return list<int>|null */
    private static function bytes(string $ip): ?array
    {
        if (!mb_check_encoding($ip, 'UTF-8') || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        $unpacked = unpack('C*', $packed);
        if ($unpacked === false) {
            return null;
        }
        $bytes = [];
        foreach ($unpacked as $byte) {
            if (!is_int($byte)) {
                return null;
            }
            $bytes[] = $byte;
        }
        return $bytes;
    }

    /** @param list<int> $bytes */
    private static function isMapped(array $bytes): bool
    {
        return count($bytes) === 16
            && array_slice($bytes, 0, 12) === [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 255, 255];
    }
}
