<?php

declare(strict_types=1);

namespace ShopeePay\Dto\Subscription;

use InvalidArgumentException;

/**
 * Poll the status of a Subscription debit attempt. Service code 55,
 * sent to `/v1.0/debit/status` (same as Link & Pay; the gateway uses
 * the originalReferenceNo / serviceCode pair to route the inquiry).
 *
 * Caller MUST supply at least one of `originalReferenceNo` (ShopeePay's
 * id) or `originalPartnerReferenceNo` (caller's id). serviceCode defaults
 * to "54" (the subscription-debit create). For refund status use "58".
 */
final class CheckStatusRequest
{
    public const SVC_CREATE = '54';
    public const SVC_REFUND = '58';

    public readonly ?string $originalReferenceNo;
    public readonly ?string $originalPartnerReferenceNo;
    public readonly string $serviceCode;

    public function __construct(
        ?string $originalReferenceNo = null,
        ?string $originalPartnerReferenceNo = null,
        string $serviceCode = self::SVC_CREATE,
    ) {
        if (($originalReferenceNo === null || trim($originalReferenceNo) === '')
            && ($originalPartnerReferenceNo === null || trim($originalPartnerReferenceNo) === '')
        ) {
            throw new InvalidArgumentException(
                'CheckStatusRequest requires at least one of originalReferenceNo or originalPartnerReferenceNo',
            );
        }
        if (trim($serviceCode) === '') {
            throw new InvalidArgumentException('serviceCode must not be empty');
        }

        $this->originalReferenceNo        = $originalReferenceNo;
        $this->originalPartnerReferenceNo = $originalPartnerReferenceNo;
        $this->serviceCode                = $serviceCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = ['serviceCode' => $this->serviceCode];
        if ($this->originalReferenceNo !== null) {
            $body['originalReferenceNo'] = $this->originalReferenceNo;
        }
        if ($this->originalPartnerReferenceNo !== null) {
            $body['originalPartnerReferenceNo'] = $this->originalPartnerReferenceNo;
        }
        return $body;
    }
}
