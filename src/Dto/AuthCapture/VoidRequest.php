<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AuthCapture;

use InvalidArgumentException;

/**
 * Cancel an outstanding authorization before any capture occurs.
 * Service code 67, path `/v1.0/auth/void`.
 *
 * Per the design doc state machine, **void must occur before capture**.
 * v1 does NOT enforce this client-side; voiding a captured auth returns
 * an `ApiException` from the gateway. Use `refund()` to reverse a
 * captured charge.
 *
 * Either `originalReferenceNo` (ShopeePay's auth id) or
 * `originalPartnerReferenceNo` (caller's auth id) must be supplied;
 * supplying both disambiguates if the caller's reference was reused.
 *
 * `partnerReferenceNo` is the caller's idempotency key for the void
 * itself — distinct from the authorization's.
 */
final class VoidRequest
{
    public readonly ?string $originalReferenceNo;
    public readonly ?string $originalPartnerReferenceNo;
    public readonly string $partnerReferenceNo;
    public readonly ?string $reason;

    /** @var array<string, mixed> */
    public readonly array $additionalInfo;

    /**
     * @param array<string, mixed> $additionalInfo
     */
    public function __construct(
        string $partnerReferenceNo,
        ?string $originalReferenceNo = null,
        ?string $originalPartnerReferenceNo = null,
        ?string $reason = null,
        array $additionalInfo = [],
    ) {
        if (($originalReferenceNo === null || trim($originalReferenceNo) === '')
            && ($originalPartnerReferenceNo === null || trim($originalPartnerReferenceNo) === '')
        ) {
            throw new InvalidArgumentException(
                'VoidRequest requires at least one of originalReferenceNo or originalPartnerReferenceNo',
            );
        }
        if (trim($partnerReferenceNo) === '') {
            throw new InvalidArgumentException('partnerReferenceNo must not be empty');
        }

        $this->partnerReferenceNo         = $partnerReferenceNo;
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
            'partnerReferenceNo' => $this->partnerReferenceNo,
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
