<?php

declare(strict_types=1);

namespace ShopeePay\Dto\Subscription;

use ShopeePay\Dto\Common\Money;

/**
 * Result of a Subscription refund call (svc 58). Shape mirrors
 * `LinkAndPay\RefundResponse` for now; the eventual completion notify
 * lands on svc 52 (not 56) and is routed by `EventFactory` to
 * `RefundCompleted` with the parent subscription's serviceCode in `raw`.
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
            return null;
        }
    }
}
