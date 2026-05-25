<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AccountLinking;

/**
 * Result of an account-linking inquiry. `accountStatus` is the gateway's
 * authoritative view of whether the binding is still usable.
 *
 * Known status values (not exhaustive): "ACTIVE", "INACTIVE". Anything else
 * is surfaced verbatim — callers should branch on the known ones and treat
 * the rest as "unknown, do not transact."
 */
final class InquiryResponse
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $responseCode,
        public readonly string $responseMessage,
        public readonly string $accountStatus,
        public readonly ?string $referenceNo,
        public readonly ?string $partnerReferenceNo,
        public readonly array $raw,
    ) {
    }

    public function isActive(): bool
    {
        return strtoupper($this->accountStatus) === 'ACTIVE';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            responseCode:       is_string($payload['responseCode'] ?? null) ? $payload['responseCode'] : '',
            responseMessage:    is_string($payload['responseMessage'] ?? null) ? $payload['responseMessage'] : '',
            accountStatus:      is_string($payload['accountStatus'] ?? null) ? $payload['accountStatus'] : '',
            referenceNo:        is_string($payload['referenceNo'] ?? null) ? $payload['referenceNo'] : null,
            partnerReferenceNo: is_string($payload['partnerReferenceNo'] ?? null) ? $payload['partnerReferenceNo'] : null,
            raw:                $payload,
        );
    }
}
