<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AuthCapture;

use ShopeePay\Dto\Common\Money;

/**
 * Result of an Auth & Capture capture call (svc 65). A synchronous
 * acknowledgement that the capture has been queued — final settlement
 * arrives via the async `AuthCaptured` webhook (notify), at which point
 * `queryCapture()` returns a terminal status.
 *
 * `referenceNo` is ShopeePay's id for the capture (distinct from the
 * authorization's). `capturedAmount` echoes the actual amount the gateway
 * captured; it may be ≤ requested if the gateway prorated.
 */
final class CaptureResponse
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $responseCode,
        public readonly string $responseMessage,
        public readonly ?string $referenceNo,
        public readonly ?string $partnerReferenceNo,
        public readonly ?string $originalReferenceNo,
        public readonly ?string $originalPartnerReferenceNo,
        public readonly ?Money $capturedAmount,
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
            capturedAmount:             self::moneyOrNull($payload['capturedAmount'] ?? null),
            raw:                        $payload,
        );
    }

    private static function moneyOrNull(mixed $value): ?Money
    {
        if (!is_array($value)) {
            return null;
        }
        $v = $value['value']    ?? null;
        $c = $value['currency'] ?? null;
        if (!is_string($v) || !is_string($c)) {
            return null;
        }
        try {
            return new Money($v, $c);
        } catch (\InvalidArgumentException) {
            // Malformed gateway amount — surface as null and keep $raw
            // intact rather than blowing up the whole response. Same
            // policy as LinkAndPay\RefundResponse.
            return null;
        }
    }
}
