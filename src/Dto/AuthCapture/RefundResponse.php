<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AuthCapture;

use ShopeePay\Dto\Common\Money;

/**
 * Result of an Auth & Capture refund call (svc 69). Synchronous
 * acknowledgement that the refund has been queued; final settlement
 * arrives via the `RefundCompleted` webhook (notify) — unlike debit
 * refunds, which reuse svc 56/52 with a refund-shape body, AuthCapture
 * refunds get their own notify channel (see `Webhook\EventFactory`
 * dispatch).
 *
 * `refundAmount` is the actual refunded amount (may be ≤ requested if
 * the gateway prorated). Returned as a `Money` value object; null if the
 * gateway response is malformed (caller can read `$raw` for forensics).
 */
final class RefundResponse
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $responseCode,
        public readonly string $responseMessage,
        public readonly ?string $refundNo,
        public readonly ?string $partnerRefundNo,
        public readonly ?Money $refundAmount,
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
            refundNo:                   is_string($payload['refundNo'] ?? null) ? $payload['refundNo'] : null,
            partnerRefundNo:            is_string($payload['partnerRefundNo'] ?? null) ? $payload['partnerRefundNo'] : null,
            refundAmount:               self::moneyOrNull($payload['refundAmount'] ?? null),
            originalReferenceNo:        is_string($payload['originalReferenceNo'] ?? null) ? $payload['originalReferenceNo'] : null,
            originalPartnerReferenceNo: is_string($payload['originalPartnerReferenceNo'] ?? null) ? $payload['originalPartnerReferenceNo'] : null,
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
            // intact rather than blowing up the whole response.
            return null;
        }
    }
}
