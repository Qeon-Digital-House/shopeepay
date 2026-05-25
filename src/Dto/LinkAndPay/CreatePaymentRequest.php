<?php

declare(strict_types=1);

namespace ShopeePay\Dto\LinkAndPay;

use InvalidArgumentException;
use ShopeePay\Dto\Common\Money;

/**
 * Initiate a Link & Pay debit-host-to-host payment. Service code 54.
 * Sends to `/v1.1/debit/payment-host-to-host`. The response carries a
 * `webRedirectUrl` the caller redirects the user to so they can confirm
 * the charge inside ShopeePay.
 *
 * `partnerReferenceNo` MUST be unique per attempt — v1 will not auto-
 * generate one (design doc, "Things explicitly OUT of scope"). Sending
 * the same reference twice risks gateway-side dedup behavior that depends
 * on whether the original attempt is still in flight.
 *
 * `accountToken` is the value returned by a prior
 * `AccountLinkingService::bind()`. Treat it as a secret — `LogScrubber`
 * redacts it before the PSR-3 logger sees it.
 *
 * `additionalInfo` is the gateway's grab-bag for optional metadata
 * (description, item list, productId). Caller passes a key/value map and
 * the SDK serializes it verbatim.
 */
final class CreatePaymentRequest
{
    public readonly string $partnerReferenceNo;
    public readonly Money $amount;
    public readonly string $accountToken;

    /** @var array<string, mixed> */
    public readonly array $additionalInfo;

    /**
     * @param array<string, mixed> $additionalInfo
     */
    public function __construct(
        string $partnerReferenceNo,
        Money $amount,
        string $accountToken,
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
        if ($this->additionalInfo !== []) {
            $body['additionalInfo'] = $this->additionalInfo;
        }
        return $body;
    }
}
