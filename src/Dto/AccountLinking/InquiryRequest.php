<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AccountLinking;

use InvalidArgumentException;

/**
 * Check the status of a bound `accountToken`. Service code 08. Synchronous.
 *
 * Use this when your records say the binding is active but you want to
 * confirm before initiating a large transaction. The gateway returns the
 * current account status (Active, Inactive, etc.) in the response.
 */
final class InquiryRequest
{
    public readonly string $accountToken;
    public readonly string $partnerReferenceNo;

    public function __construct(string $accountToken, string $partnerReferenceNo)
    {
        if (trim($accountToken) === '') {
            throw new InvalidArgumentException('accountToken must not be empty');
        }
        if (trim($partnerReferenceNo) === '') {
            throw new InvalidArgumentException('partnerReferenceNo must not be empty');
        }

        $this->accountToken       = $accountToken;
        $this->partnerReferenceNo = $partnerReferenceNo;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tokenId'            => $this->accountToken,
            'partnerReferenceNo' => $this->partnerReferenceNo,
        ];
    }
}
