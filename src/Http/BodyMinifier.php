<?php

declare(strict_types=1);

namespace ShopeePay\Http;

use ShopeePay\Exception\ConfigException;

/**
 * Canonical JSON encoding for the HMAC stringToSign body hash.
 *
 * The flags matter and are NOT negotiable:
 *   - JSON_UNESCAPED_SLASHES  → "/" stays "/", not "\/". Required so a URL
 *     embedded in the body hashes identically to what ShopeePay computes
 *     server-side.
 *   - JSON_UNESCAPED_UNICODE  → multibyte chars stay literal. Matches the
 *     Java/Go reference SDK output.
 *   - No PRETTY_PRINT, no whitespace — the body must be the exact bytes that
 *     go over the wire.
 *
 * Anything that would produce non-deterministic JSON (resources, closures,
 * NAN/INF) raises ConfigException — those should never make it into a
 * request body.
 */
final class BodyMinifier
{
    /**
     * @param array<string, mixed>|object $body
     */
    public function encode(array|object $body): string
    {
        $json = json_encode(
            $body,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        // json_encode with JSON_THROW_ON_ERROR cannot return false, but the
        // return type is `string|false` in PHP <8.3, so narrow defensively.
        if (!is_string($json)) {
            throw new ConfigException('json_encode produced a non-string result');
        }

        return $json;
    }

    /**
     * Lowercase hex of SHA256(minifiedBody). This is the "Hex(SHA256(body))"
     * the design refers to in the HMAC and webhook stringToSign formulas.
     */
    public function bodyHash(string $minifiedBody): string
    {
        return hash('sha256', $minifiedBody);
    }
}
