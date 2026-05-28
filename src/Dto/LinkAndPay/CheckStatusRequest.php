<?php

declare(strict_types=1);

namespace ShopeePay\Dto\LinkAndPay;

use InvalidArgumentException;

/**
 * Poll the status of a previously-created Link & Pay transaction.
 * Service code 55. Sent to `/v1.0/debit/status`.
 *
 * Caller MUST supply at least one of `originalReferenceNo` (ShopeePay's id)
 * or `originalPartnerReferenceNo` (caller's id). Supplying both is allowed
 * and provides disambiguation if the caller's reference is reused.
 *
 * `serviceCode` defaults to "54" (the create svc). For refund status,
 * pass "58". The gateway uses this to route the inquiry to the correct
 * subsystem.
 */
final class CheckStatusRequest
{
    /** Default to the Link & Pay create service code. */
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
