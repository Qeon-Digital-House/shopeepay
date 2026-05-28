<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AuthCapture;

use InvalidArgumentException;

/**
 * Poll the status of a prior `void()`. Service code 68, path
 * `/v1.0/auth/void/status` (SNAP BI convention, pending sandbox
 * confirmation).
 *
 * Voids are synchronous in v1's design model, so this query mostly
 * exists for reconciliation / forensic lookup. The reference ids here
 * are the VOID ids (returned from `VoidResponse`).
 */
final class QueryVoidRequest
{
    public const SERVICE_CODE = '67';

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
                'QueryVoidRequest requires at least one of originalReferenceNo or originalPartnerReferenceNo',
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
