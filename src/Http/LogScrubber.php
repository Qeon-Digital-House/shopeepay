<?php

declare(strict_types=1);

namespace ShopeePay\Http;

/**
 * Strips secrets out of arrays/strings before they reach a PSR-3 logger.
 *
 * Two redaction triggers:
 *   1. Field name (case-insensitive) matches the SENSITIVE_KEYS list. Header
 *      casing varies (X-SIGNATURE / x-signature / Signature), so we compare
 *      a normalized lowercased version.
 *   2. A value looks like a PEM block — anything containing "-----BEGIN".
 *      Catches both the private key the SDK signs with and any
 *      ShopeePay-issued public key that ends up in a log context.
 *
 * Redacted values become "[REDACTED:N]" where N is the original byte length.
 * Length leaks no secret material and is useful for debugging "wrong-shaped
 * input" bugs without seeing the actual content.
 */
final class LogScrubber
{
    private const SENSITIVE_KEYS = [
        'accesstoken',
        'access_token',
        'authorization',
        'x-signature',
        'signature',
        'mobilenumber',
        'mobile_number',
        'accounttoken',
        'account_token',
        'clientsecret',
        'client_secret',
        'privatekey',
        'private_key',
    ];

    /**
     * @param  array<int|string, mixed> $context
     * @return array<int|string, mixed>
     */
    public function scrub(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $out[$key] = $this->scrubValue($key, $value);
        }
        return $out;
    }

    private function scrubValue(int|string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->scrub($value);
        }

        if (is_string($value)) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                return $this->redact($value);
            }
            if (str_contains($value, '-----BEGIN')) {
                return $this->redact($value);
            }
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        return in_array($normalized, self::SENSITIVE_KEYS, true);
    }

    private function redact(string $value): string
    {
        return sprintf('[REDACTED:%d]', strlen($value));
    }
}
