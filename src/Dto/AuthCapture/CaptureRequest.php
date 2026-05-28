<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AuthCapture;

use InvalidArgumentException;
use ShopeePay\Dto\Common\Money;

/**
 * Capture (settle) funds from a prior `authorize()`. Service code 65,
 * path `/v1.0/auth/capture`.
 *
 * `captureAmount` may equal or be less than the authorized amount; any
 * unreserved balance is released. Per the design doc state machine,
 * **only one partial capture per authorization is allowed** — a second
 * capture attempt returns an `ApiException`. v1 does NOT enforce this
 * client-side; the gateway is the source of truth.
 *
 * Capture must occur before the authorization's `validUpTo` expires.
 *
 * Either `originalReferenceNo` (ShopeePay's auth id) or
 * `originalPartnerReferenceNo` (caller's auth id) must be supplied;
 * supplying both is allowed and disambiguates if the caller's reference
 * has been reused. `partnerReferenceNo` is the caller's idempotency key
 * for the capture itself — distinct from the authorization's.
 */
final class CaptureRequest
{
    public readonly ?string $originalReferenceNo;
    public readonly ?string $originalPartnerReferenceNo;
    public readonly string $partnerReferenceNo;
    public readonly Money $captureAmount;

    /** @var array<string, mixed> */
    public readonly array $additionalInfo;

    /**
     * @param array<string, mixed> $additionalInfo
     */
    public function __construct(
        Money $captureAmount,
        string $partnerReferenceNo,
        ?string $originalReferenceNo = null,
        ?string $originalPartnerReferenceNo = null,
        array $additionalInfo = [],
    ) {
        if (($originalReferenceNo === null || trim($originalReferenceNo) === '')
            && ($originalPartnerReferenceNo === null || trim($originalPartnerReferenceNo) === '')
        ) {
            throw new InvalidArgumentException(
                'CaptureRequest requires at least one of originalReferenceNo or originalPartnerReferenceNo',
            );
        }
        if (trim($partnerReferenceNo) === '') {
            throw new InvalidArgumentException('partnerReferenceNo must not be empty');
        }

        $this->captureAmount              = $captureAmount;
        $this->partnerReferenceNo         = $partnerReferenceNo;
        $this->originalReferenceNo        = $originalReferenceNo;
        $this->originalPartnerReferenceNo = $originalPartnerReferenceNo;
        $this->additionalInfo             = $additionalInfo;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'partnerReferenceNo' => $this->partnerReferenceNo,
            'captureAmount'      => $this->captureAmount->toArray(),
        ];
        if ($this->originalReferenceNo !== null) {
            $body['originalReferenceNo'] = $this->originalReferenceNo;
        }
        if ($this->originalPartnerReferenceNo !== null) {
            $body['originalPartnerReferenceNo'] = $this->originalPartnerReferenceNo;
        }
        if ($this->additionalInfo !== []) {
            $body['additionalInfo'] = $this->additionalInfo;
        }
        return $body;
    }
}
