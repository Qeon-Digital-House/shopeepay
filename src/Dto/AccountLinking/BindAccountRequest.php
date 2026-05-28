<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AccountLinking;

use InvalidArgumentException;

/**
 * Exchange an `authCode` (returned to the redirectUrl after the user grants
 * consent) for a long-lived `accountToken`. Service code 07.
 *
 * `authCode` expires 30 minutes after issuance; sending an expired one
 * surfaces as an `ApiException` with responseCode `4030700`-class.
 *
 * `partnerReferenceNo` MUST be unique per attempt — replaying the same
 * pair against the gateway may either succeed idempotently or fail with a
 * duplicate-reference error depending on the gateway's mood; either way,
 * generate a fresh reference for each bind attempt.
 */
final class BindAccountRequest
{
    public readonly string $authCode;
    public readonly string $partnerReferenceNo;

    public function __construct(string $authCode, string $partnerReferenceNo)
    {
        if (trim($authCode) === '') {
            throw new InvalidArgumentException('authCode must not be empty');
        }
        if (trim($partnerReferenceNo) === '') {
            throw new InvalidArgumentException('partnerReferenceNo must not be empty');
        }

        $this->authCode           = $authCode;
        $this->partnerReferenceNo = $partnerReferenceNo;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'authCode'           => $this->authCode,
            'partnerReferenceNo' => $this->partnerReferenceNo,
        ];
    }
}
