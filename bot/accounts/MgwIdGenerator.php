<?php
declare(strict_types=1);

final class MgwIdGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const INTERNAL_PATTERN = '/^MGW-[0-9A-HJKMNP-TV-Z]{16}$/';
    private const PUBLIC_PATTERN = '/^MGW-ID-([0-9A-HJKMNP-TV-Z]{16})$/';

    public static function generate(): string
    {
        $bytes = random_bytes(10);
        $buffer = 0;
        $bits = 0;
        $encoded = '';

        foreach (unpack('C*', $bytes) ?: [] as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($buffer >> $bits) & 31];
                $buffer &= (1 << $bits) - 1;
            }
        }

        if ($bits > 0) {
            $encoded .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return 'MGW-' . $encoded;
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::INTERNAL_PATTERN, strtoupper(trim($value))) === 1;
    }

    public static function toPublic(string $value): string
    {
        $internal = strtoupper(trim($value));
        if (!self::isValid($internal)) throw new InvalidArgumentException('MGW id is invalid.');
        return 'MGW-ID-' . substr($internal, 4);
    }

    public static function fromPublic(string $value): ?string
    {
        $normalized = strtoupper(trim($value));
        if (self::isValid($normalized)) return $normalized;
        if (preg_match(self::PUBLIC_PATTERN, $normalized, $match) !== 1) return null;
        return 'MGW-' . $match[1];
    }
}
