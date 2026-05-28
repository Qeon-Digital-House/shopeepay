<?php

declare(strict_types=1);

namespace ShopeePay\Dto\Subscription;

use InvalidArgumentException;
use ShopeePay\Dto\Common\Money;

/**
 * Refund a previously-completed Subscription debit. Service code 58.
 *
 * This DTO is intentionally separate from `LinkAndPay\RefundRequest`
 * (locked decision #4) — the request shapes drift between services
 * (e.g. Subscription refunds may need `subscriptionId` in the body so
 * the gateway can apply per-subscription refund limits). Merging would
 * force a discriminator and break the service-per-flow pattern.
 */
final class RefundRequest
{
    public readonly ?string $originalReferenceNo;
    public readonly ?string $originalPartnerReferenceNo;
    public readonly Money $refundAmount;
    public readonly string $partnerRefundNo;
    public readonly ?string $subscriptionId;
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
        ?string $subscriptionId = null,
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
        $this->subscriptionId             = $subscriptionId;
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
        if ($this->subscriptionId !== null) {
            $body['subscriptionId'] = $this->subscriptionId;
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
