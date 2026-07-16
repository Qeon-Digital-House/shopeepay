<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AccountLinking;

use InvalidArgumentException;

/**
 * Check the status of a bound `accountToken`. Service code 08. Synchronous.
 *
 * Sandbox-verified (2026-07-09): the binding is identified by
 * additionalInfo.accountToken; partnerReferenceNo alone yields 4040811. The
 * top-level merchantId is added by AccountLinkingService::inquiry().
 */
final class InquiryRequest
{
    public readonly string $accountToken;
    public readonly ?string $partnerReferenceNo;

    public function __construct(string $accountToken, ?string $partnerReferenceNo = null)
    {
        if (trim($accountToken) === "") {
            throw new InvalidArgumentException("accountToken must not be empty");
        }

        $this->accountToken       = $accountToken;
        $this->partnerReferenceNo = $partnerReferenceNo;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            "additionalInfo" => ["accountToken" => $this->accountToken],
        ];
        if ($this->partnerReferenceNo !== null && trim($this->partnerReferenceNo) !== "") {
            $body["partnerReferenceNo"] = $this->partnerReferenceNo;
        }

        return $body;
    }
}