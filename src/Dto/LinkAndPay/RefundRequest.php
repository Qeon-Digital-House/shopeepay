<?php

declare(strict_types=1);

namespace ShopeePay\Dto\LinkAndPay;

use InvalidArgumentException;
use ShopeePay\Dto\Common\Money;

/**
 * Refund a previously-completed Link & Pay transaction. Service code 58,
 * path `/v1.0/debit/refund`.
 *
 * Caller MUST supply at least one of `originalReferenceNo` or
 * `originalPartnerReferenceNo` — the gateway uses these to locate the
 * original transaction. `partnerRefundNo` is the caller's idempotency
 * key for the refund itself; it MUST be unique per refund attempt
 * (the gateway uses it to dedup re-tries).
 *
 * Refund time-window enforcement is NOT done client-side in v1
 * (see design doc, "Things explicitly OUT of scope"). The gateway
 * surfaces a refund-too-old error as an `ApiException`.
 *
 * This class is intentionally separate from Subscription/AuthCapture
 * refund DTOs (locked decision #4) — the request shapes drift between
 * services and merging them would force a discriminator.
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
