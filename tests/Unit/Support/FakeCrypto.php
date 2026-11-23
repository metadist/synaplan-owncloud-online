<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Support;

use OCP\Security\ICrypto;

/**
 * Reversible "encryption" for tests: the value under a marker, so a test sees
 * that a value was stored encrypted without depending on the server secret.
 * A value encrypted by another secret ($foreign) does not decrypt, as with
 * the server's ICrypto after the secret changed.
 */
final class FakeCrypto implements ICrypto
{
    public const MARKER = 'sealed:';

    public function calculateHMAC($message, $password = '')
    {
        return hash_hmac('sha512', (string) $message, 'test-secret');
    }

    public function encrypt($plaintext, $password = '')
    {
        return self::MARKER . base64_encode((string) $plaintext);
    }

    public function decrypt($authenticatedCiphertext, $password = '')
    {
        $value = (string) $authenticatedCiphertext;
        if (!str_starts_with($value, self::MARKER)) {
            throw new \Exception('HMAC does not match.');
        }

        return (string) base64_decode(substr($value, strlen(self::MARKER)), true);
    }

    /** What the storage writes for $plaintext. */
    public static function stored(string $plaintext): string
    {
        return 'enc:' . self::MARKER . base64_encode($plaintext);
    }
}
