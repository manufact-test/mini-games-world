<?php
declare(strict_types=1);

final class RsaJwkPublicKey
{
    public static function toPem(array $jwk): ?string
    {
        $modulus = self::base64UrlDecode((string)($jwk['n'] ?? ''));
        $exponent = self::base64UrlDecode((string)($jwk['e'] ?? ''));
        if ($modulus === null || $modulus === '' || $exponent === null || $exponent === '') {
            return null;
        }

        $rsaPublicKey = self::sequence(
            self::integer($modulus)
            . self::integer($exponent)
        );
        $rsaEncryptionAlgorithm = hex2bin('300d06092a864886f70d0101010500');
        if ($rsaEncryptionAlgorithm === false) {
            return null;
        }
        $subjectPublicKeyInfo = self::sequence(
            $rsaEncryptionAlgorithm
            . self::bitString($rsaPublicKey)
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::length(strlen($bytes)) . $bytes;
    }

    private static function sequence(string $value): string
    {
        return "\x30" . self::length(strlen($value)) . $value;
    }

    private static function bitString(string $value): string
    {
        $value = "\x00" . $value;
        return "\x03" . self::length(strlen($value)) . $value;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }
        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
