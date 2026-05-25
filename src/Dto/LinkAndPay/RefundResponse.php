<?php

declare(strict_types=1);

namespace ShopeePay\Dto\LinkAndPay;

use ShopeePay\Dto\Common\Money;

/**
 * Result of a Link & Pay refund call (svc 58).
 *
 * `refundNo` is ShopeePay's id for the refund. `partnerRefundNo` echoes
 * the caller-supplied idempotency key. `refundAmount` reports the actual
 * refunded amount — may be ≤ requested if the gateway prorated for
 * fees/disputes.
 *
 * A successful response from the gateway does NOT mean the refund has
 * settled in the user's wallet — partner-side eventual completion is
 * signalled via the same notify webhook channel as the original
 * payment (svc 56 with a refund-shape body; EventFactory routes it to
 * RefundCompleted).
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
            // Gateway returned a malformed amount — surface as null rather
            // than blowing up the whole response. Caller can read $raw.
            return null;
        }
    }
}
