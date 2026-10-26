<?php

namespace App\Services\ClientBuilder;

use RuntimeException;

/**
 * Writes a single-file zip encrypted with WinZip AES-256 (AE-2), the format
 * pyzipper reads in rdgen's "Decrypt and Mask Secrets" step.
 *
 * Done by hand rather than with ZipArchive because the Docker image's PHP has
 * no zip extension, and libzip's AES support depends on how it was built. The
 * format is small: PBKDF2-SHA1 key derivation, AES-CTR with a little-endian
 * counter starting at 1, and a truncated HMAC-SHA1 over the ciphertext.
 * See https://www.winzip.com/en/support/aes-encryption/.
 */
class AesZip
{
    private const ITERATIONS = 1000;

    private const KEY_BYTES = 32; // AES-256

    private const SALT_BYTES = 16;

    public static function single(string $name, string $contents, string $password): string
    {
        if ($password === '') {
            throw new RuntimeException('The zip password is empty.');
        }

        $salt = random_bytes(self::SALT_BYTES);
        $derived = hash_pbkdf2('sha1', $password, $salt, self::ITERATIONS, 2 * self::KEY_BYTES + 2, true);
        $encKey = substr($derived, 0, self::KEY_BYTES);
        $macKey = substr($derived, self::KEY_BYTES, self::KEY_BYTES);
        $verifier = substr($derived, 2 * self::KEY_BYTES, 2);

        $cipher = self::ctr($encKey, $contents);
        $auth = substr(hash_hmac('sha1', $cipher, $macKey, true), 0, 10);
        $payload = $salt.$verifier.$cipher.$auth;

        // Extra field 0x9901: data size 7, vendor version 2 (AE-2), vendor id
        // "AE", strength 3 (256-bit), real compression method 0 (stored).
        $extra = pack('vvv', 0x9901, 7, 2).'AE'.pack('Cv', 3, 0);

        [$time, $date] = self::dosTime(time());
        $flags = 0x0001 | 0x0800; // encrypted, UTF-8 name
        $method = 99;             // AES
        $crc = 0;                 // AE-2 stores no CRC
        $size = strlen($payload);
        $plainSize = strlen($contents);

        $local = pack('VvvvvvVVVvv', 0x04034B50, 51, $flags, $method, $time, $date, $crc, $size, $plainSize,
            strlen($name), strlen($extra)).$name.$extra;

        $central = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 51, 51, $flags, $method, $time, $date, $crc, $size,
            $plainSize, strlen($name), strlen($extra), 0, 0, 0, 0, 0).$name.$extra;

        $offset = strlen($local) + $size;
        $end = pack('VvvvvVVv', 0x06054B50, 0, 0, 1, 1, strlen($central), $offset, 0);

        return $local.$payload.$central.$end;
    }

    /** AES-CTR as WinZip does it: 128-bit little-endian counter from 1. */
    private static function ctr(string $key, string $data): string
    {
        $out = '';
        $blocks = (int) ceil(strlen($data) / 16);
        for ($i = 0; $i < $blocks; $i++) {
            $counter = pack('P', $i + 1).str_repeat("\0", 8);
            $stream = openssl_encrypt($counter, 'aes-256-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
            if ($stream === false) {
                throw new RuntimeException('AES is not available in this PHP build.');
            }
            $chunk = substr($data, $i * 16, 16);
            $out .= $chunk ^ substr($stream, 0, strlen($chunk));
        }

        return $out;
    }

    /** @return array{0: int, 1: int} */
    private static function dosTime(int $ts): array
    {
        $d = getdate($ts);
        $time = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
        $date = (max(0, $d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];

        return [$time, $date];
    }
}
