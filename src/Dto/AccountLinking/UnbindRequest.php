<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AccountLinking;

use InvalidArgumentException;

/**
 * Revoke an `accountToken`. Service code 09. Synchronous.
 *
 * After unbind succeeds, the token is no longer usable for new transactions.
 * In-flight transactions referencing it are not rolled back — they complete
 * (or fail) on their own. Refunds against already-completed payments do not
 * require an active binding.
 */
final class UnbindRequest
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
