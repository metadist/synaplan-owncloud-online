<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Service;

/**
 * Checks a Synaplan notification: a JSON Web Token in compact JWS form, typed
 * `synaplan-notification+jwt`, EdDSA (Ed25519) with a key from Synaplan's JWK
 * Set, issued by the Synaplan this ownCloud.online talks to (`iss`, as
 * Synaplan's `/.well-known/synaplan-configuration` names it), addressed to
 * this registration (`aud`), inside its window (`iat`, `exp`). The vectors in
 * `tests/fixtures/synaplan-contract/notify-vectors.json` pin it.
 */
final class NotificationSignature
{
    public const OK = 'ok';
    public const BAD_SIGNATURE = 'bad_signature';
    public const WRONG_ISSUER = 'wrong_issuer';
    public const WRONG_INSTANCE = 'wrong_instance';
    public const STALE = 'stale';
    public const REPLAY = 'replay';

    private const TYPE = 'synaplan-notification+jwt';
    private const SKEW_SECONDS = 60;
    private const PREFIX = 'ed25519:';

    /**
     * @param list<array<string, mixed>> $keys
     */
    public static function verify(string $token, string $issuer, string $instanceId, array $keys, int $now): string
    {
        $parts = explode('.', trim($token));
        $header = self::part($token, 0);
        if (count($parts) !== 3 || ($header['alg'] ?? null) !== 'EdDSA' || ($header['typ'] ?? null) !== self::TYPE) {
            return self::BAD_SIGNATURE;
        }
        if (!self::signedByOneOf($parts[0] . '.' . $parts[1], $parts[2], $keys)) {
            return self::BAD_SIGNATURE;
        }
        $claims = self::claims($token);
        $iss = $claims['iss'] ?? null;
        // Synaplan signs with its issuer without the trailing slash, and the
        // app stores it so; an address written with one names the same issuer.
        if (rtrim($issuer, '/') === '' || !is_string($iss) || rtrim($iss, '/') !== rtrim($issuer, '/')) {
            return self::WRONG_ISSUER;
        }
        if ($instanceId === '' || ($claims['aud'] ?? null) !== $instanceId) {
            return self::WRONG_INSTANCE;
        }
        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp) || $now >= $exp || $iat > $now + self::SKEW_SECONDS) {
            return self::STALE;
        }

        return self::OK;
    }

    /**
     * The token's claims, unverified: call verify() first.
     *
     * @return array<string, mixed>
     */
    public static function claims(string $token): array
    {
        return self::part($token, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private static function part(string $token, int $index): array
    {
        $parts = explode('.', trim($token));
        $decoded = count($parts) === 3 ? json_decode(self::b64decode($parts[$index]) ?? '', true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<array<string, mixed>> $keys
     */
    private static function signedByOneOf(string $message, string $encodedSignature, array $keys): bool
    {
        $signature = self::b64decode($encodedSignature);
        if ($signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        foreach ($keys as $key) {
            $publicKey = $key['publicKey'] ?? null;
            $raw = is_string($publicKey) && str_starts_with($publicKey, self::PREFIX)
                ? self::b64decode(substr($publicKey, strlen(self::PREFIX)))
                : null;
            if ($raw !== null && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && sodium_crypto_sign_verify_detached($signature, $message, $raw)) {
                return true;
            }
        }

        return false;
    }

    private static function b64decode(string $encoded): ?string
    {
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
