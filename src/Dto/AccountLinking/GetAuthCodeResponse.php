<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AccountLinking;

/**
 * Successful response from the server-to-server get-auth-code call (svc 10).
 *
 * The gateway returns the `authCode` directly in the body (responseCode
 * 2001000). Per the SNAP BI flow, the merchant then appends this `authCode`
 * to the static consent URL the user's browser visits; after the user grants
 * consent ShopeePay redirects back to the request's `redirectUrl` with the
 * `authCode` again. Either code is then exchanged for an `accountToken` via
 * `AccountLinkingService::bind()`.
 *
 * `authCode` expires 30 minutes after issuance.
 *
 * `raw` keeps the full decoded payload so callers can read fields we haven't
 * surfaced as properties.
 */
final class GetAuthCodeResponse
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $responseCode,
        public readonly string $responseMessage,
        public readonly string $authCode,
        public readonly ?string $state,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            responseCode:    self::str($payload, 'responseCode'),
            responseMessage: self::str($payload, 'responseMessage'),
            authCode:        self::str($payload, 'authCode'),
            state:           self::strOrNull($payload, 'state'),
            raw:             $payload,
        );
    }

    /** @param array<string, mixed> $a */
    private static function str(array $a, string $k): string
    {
        return is_string($a[$k] ?? null) ? $a[$k] : '';
    }

    /** @param array<string, mixed> $a */
    private static function strOrNull(array $a, string $k): ?string
    {
        return is_string($a[$k] ?? null) ? $a[$k] : null;
    }
}
