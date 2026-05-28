<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AuthCapture;

use InvalidArgumentException;
use ShopeePay\Dto\Common\Money;

/**
 * Refund a previously-captured Auth & Capture transaction. Service code
 * 69, path `/v1.0/auth/refund` (the one AuthCapture path the design doc
 * pins explicitly — line 230 of the approved design).
 *
 * Per the design doc state machine, **refund must occur after a
 * successful capture, against the captured amount**. v1 does NOT enforce
 * this client-side; refunding an un-captured auth returns an
 * `ApiException` from the gateway. Use `void()` to release an un-captured
 * authorization.
 *
 * The reference ids here point at the CAPTURE, not the authorization —
 * the gateway needs to locate the settled transaction to issue a reversal.
 *
 * `partnerRefundNo` is the caller's idempotency key for the refund
 * itself; it MUST be unique per refund attempt (the gateway uses it to
 * dedup retries).
 *
 * This class is intentionally separate from `LinkAndPay\RefundRequest`
 * and `Subscription\RefundRequest` (locked decision #4) — the path
 * (`/v1.0/auth/refund` vs `/v1.0/debit/refund`) and svc code (69 vs 58)
 * both differ.
 */
final class RefundRequest
{
    public readonly ?string $originalReferenceNo;
    public readonly ?string $originalPartnerReferenceNo;
    public readonly Money $refundAmount;
    public readonly string $partnerRefundNo;
    public readonly ?string $reason;

    /** @var array<string, mixed> */
    public readonly array $additionalInfo;

    /**
     * @param array<string, mixed> $additionalInfo
     */
    public function __construct(
        Money $refundAmount,
        string $partnerRefundNo,
        ?string $originalReferenceNo = null,
        ?string $originalPartnerReferenceNo = null,
        ?string $reason = null,
        array $additionalInfo = [],
    ) {
        if (($originalReferenceNo === null || trim($originalReferenceNo) === '')
            && ($originalPartnerReferenceNo === null || trim($originalPartnerReferenceNo) === '')
        ) {
            throw new InvalidArgumentException(
                'RefundRequest requires at least one of originalReferenceNo or originalPartnerReferenceNo',
            );
        }
        if (trim($partnerRefundNo) === '') {
            throw new InvalidArgumentException('partnerRefundNo must not be empty');
        }

        $this->refundAmount               = $refundAmount;
        $this->partnerRefundNo            = $partnerRefundNo;
        $this->originalReferenceNo        = $originalReferenceNo;
        $this->originalPartnerReferenceNo = $originalPartnerReferenceNo;
        $this->reason                     = $reason;
        $this->additionalInfo             = $additionalInfo;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'partnerRefundNo' => $this->partnerRefundNo,
            'refundAmount'    => $this->refundAmount->toArray(),
        ];
        if ($this->originalReferenceNo !== null) {
            $body['originalReferenceNo'] = $this->originalReferenceNo;
        }
        if ($this->originalPartnerReferenceNo !== null) {
            $body['originalPartnerReferenceNo'] = $this->originalPartnerReferenceNo;
        }
        if ($this->reason !== null) {
            $body['reason'] = $this->reason;
        }
        if ($this->additionalInfo !== []) {
            $body['additionalInfo'] = $this->additionalInfo;
        }
        return $body;
    }
}
