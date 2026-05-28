<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AuthCapture;

/**
 * Result of an Auth & Capture authorize call (svc 63). The authorization
 * is in a pending state until the user confirms the reservation inside
 * ShopeePay (some flows redirect via `webRedirectUrl`) or until the
 * gateway auto-completes a card-on-file flow.
 *
 * `referenceNo` is ShopeePay's id for the authorization — pass this as
 * `originalReferenceNo` on the subsequent `capture()`, `void()`, or
 * `queryAuth()` call. `webRedirectUrl` may be empty for silent-auth
 * flows; treat the empty string as "no redirect required".
 *
 * Do not log `webRedirectUrl` — it may contain a one-time token.
 */
final class AuthorizeResponse
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $responseCode,
        public readonly string $responseMessage,
        public readonly string $webRedirectUrl,
        public readonly ?string $referenceNo,
        public readonly ?string $partnerReferenceNo,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            responseCode:       is_string($payload['responseCode'] ?? null) ? $payload['responseCode'] : '',
            responseMessage:    is_string($payload['responseMessage'] ?? null) ? $payload['responseMessage'] : '',
            webRedirectUrl:     is_string($payload['webRedirectUrl'] ?? null) ? $payload['webRedirectUrl'] : '',
            referenceNo:        is_string($payload['referenceNo'] ?? null) ? $payload['referenceNo'] : null,
            partnerReferenceNo: is_string($payload['partnerReferenceNo'] ?? null) ? $payload['partnerReferenceNo'] : null,
            raw:                $payload,
        );
    }
}
