<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AuthCapture;

use InvalidArgumentException;
use ShopeePay\Dto\Common\Money;

/**
 * Reserve funds against a linked account without capturing them yet.
 * Service code 63, path `/v1.0/auth/payment-host-to-host` (SNAP BI
 * convention, pending sandbox confirmation — build-order step 11).
 *
 * `amount` is the MAXIMUM that can later be captured. A subsequent
 * `capture()` may take the full amount or any smaller value (one partial
 * capture per auth — design doc, "State machines and time windows").
 * Unreserved balance is released back to the customer.
 *
 * `validUpTo` controls how long the authorization stands. Format is the
 * SNAP BI transaction-timestamp `Y-m-d\TH:i:s.vP` in Asia/Jakarta. The
 * gateway default is 24 hours; the upper bound is 14 days. v1 does NOT
 * client-side validate the window — pass a malformed value and the
 * gateway returns an `ApiException`.
 *
 * `partnerReferenceNo` MUST be unique per authorization attempt. The
 * gateway de-dupes on it.
 */
final class AuthorizeRequest
{
    public readonly string $partnerReferenceNo;
    public readonly Money $amount;
    public readonly string $accountToken;
    public readonly ?string $validUpTo;

    /** @var array<string, mixed> */
    public readonly array $additionalInfo;

    /**
     * @param array<string, mixed> $additionalInfo
     */
    public function __construct(
        string $partnerReferenceNo,
        Money $amount,
        string $accountToken,
        ?string $validUpTo = null,
        array $additionalInfo = [],
    ) {
        if (trim($partnerReferenceNo) === '') {
            throw new InvalidArgumentException('partnerReferenceNo must not be empty');
        }
        if (trim($accountToken) === '') {
            throw new InvalidArgumentException('accountToken must not be empty');
        }

        $this->partnerReferenceNo = $partnerReferenceNo;
        $this->amount             = $amount;
        $this->accountToken       = $accountToken;
        $this->validUpTo          = $validUpTo;
        $this->additionalInfo     = $additionalInfo;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'partnerReferenceNo' => $this->partnerReferenceNo,
            'amount'             => $this->amount->toArray(),
            'accountToken'       => $this->accountToken,
        ];
        if ($this->validUpTo !== null) {
            $body['validUpTo'] = $this->validUpTo;
        }
        if ($this->additionalInfo !== []) {
            $body['additionalInfo'] = $this->additionalInfo;
        }
        return $body;
    }
}
