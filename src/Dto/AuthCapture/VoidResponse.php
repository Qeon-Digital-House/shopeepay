<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AuthCapture;

/**
 * Result of an Auth & Capture void call (svc 67). Synchronous — once
 * the gateway returns success, the authorization is released and any
 * reserved customer balance is unblocked. No async notify is sent for
 * voids (design doc, service-code map).
 *
 * `referenceNo` is ShopeePay's id for the void itself.
 */
final class VoidResponse
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $responseCode,
        public readonly string $responseMessage,
        public readonly ?string $referenceNo,
        public readonly ?string $partnerReferenceNo,
        public readonly ?string $originalReferenceNo,
        public readonly ?string $originalPartnerReferenceNo,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            responseCode:               is_string($payload['responseCode'] ?? null) ? $payload['responseCode'] : '',
            responseMessage:            is_string($payload['responseMessage'] ?? null) ? $payload['responseMessage'] : '',
            referenceNo:                is_string($payload['referenceNo'] ?? null) ? $payload['referenceNo'] : null,
            partnerReferenceNo:         is_string($payload['partnerReferenceNo'] ?? null) ? $payload['partnerReferenceNo'] : null,
            originalReferenceNo:        is_string($payload['originalReferenceNo'] ?? null) ? $payload['originalReferenceNo'] : null,
            originalPartnerReferenceNo: is_string($payload['originalPartnerReferenceNo'] ?? null) ? $payload['originalPartnerReferenceNo'] : null,
            raw:                        $payload,
        );
    }
}
