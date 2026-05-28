<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AuthCapture;

use InvalidArgumentException;

/**
 * Poll the status of a prior `capture()`. Service code 66, path
 * `/v1.0/auth/capture/status` (SNAP BI convention, pending sandbox
 * confirmation).
 *
 * `originalReferenceNo` / `originalPartnerReferenceNo` refer to the
 * CAPTURE ids (returned from `CaptureResponse`), NOT the authorization
 * ids. To query the auth itself, use `QueryAuthRequest`.
 */
final class QueryCaptureRequest
{
    public const SERVICE_CODE = '65';

    public readonly ?string $originalReferenceNo;
    public readonly ?string $originalPartnerReferenceNo;

    public function __construct(
        ?string $originalReferenceNo = null,
        ?string $originalPartnerReferenceNo = null,
    ) {
        if (($originalReferenceNo === null || trim($originalReferenceNo) === '')
            && ($originalPartnerReferenceNo === null || trim($originalPartnerReferenceNo) === '')
        ) {
            throw new InvalidArgumentException(
                'QueryCaptureRequest requires at least one of originalReferenceNo or originalPartnerReferenceNo',
            );
        }

        $this->originalReferenceNo        = $originalReferenceNo;
        $this->originalPartnerReferenceNo = $originalPartnerReferenceNo;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = ['serviceCode' => self::SERVICE_CODE];
        if ($this->originalReferenceNo !== null) {
            $body['originalReferenceNo'] = $this->originalReferenceNo;
        }
        if ($this->originalPartnerReferenceNo !== null) {
            $body['originalPartnerReferenceNo'] = $this->originalPartnerReferenceNo;
        }
        return $body;
    }
}
