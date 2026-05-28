<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AuthCapture;

use InvalidArgumentException;

/**
 * Poll the status of a prior `authorize()`. Service code 64, path
 * `/v1.0/auth/status`.
 *
 * Caller MUST supply at least one of `originalReferenceNo` (ShopeePay's
 * auth id) or `originalPartnerReferenceNo` (caller's auth id). Supplying
 * both disambiguates if the caller's reference has been reused.
 *
 * The SDK does NOT auto-poll — polling cadence is the caller's job, same
 * as Link & Pay. See `examples/04-auth-capture.php` (build-order step 9)
 * for the suggested pattern.
 */
final class QueryAuthRequest
{
    public const SERVICE_CODE = '63';

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
                'QueryAuthRequest requires at least one of originalReferenceNo or originalPartnerReferenceNo',
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
