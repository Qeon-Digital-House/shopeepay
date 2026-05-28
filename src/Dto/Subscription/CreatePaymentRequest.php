<?php

declare(strict_types=1);

namespace ShopeePay\Dto\Subscription;

use InvalidArgumentException;
use ShopeePay\Dto\Common\Money;

/**
 * Initiate a Subscription recurring debit. Service code 54, sent to the
 * same `/v1.1/debit/payment-host-to-host` endpoint as Link & Pay create
 * (design doc, service-code map). The gateway disambiguates the two by
 * the presence of `subscriptionId` in the body — this DTO requires it,
 * so a Link & Pay caller cannot accidentally route through here and
 * vice versa.
 *
 * Notify on completion is service code 52 (not 56) — `EventFactory`
 * routes those to `SubscriptionPaymentCompleted` / `SubscriptionPaymentFailed`.
 *
 * `partnerReferenceNo` MUST be unique per recurring charge attempt; the
 * subscription binding itself is identified by `subscriptionId` (returned
 * to the merchant at subscription-setup time, an out-of-band step not
 * covered by v1).
 */
final class CreatePaymentRequest
{
    public readonly string $partnerReferenceNo;
    public readonly Money $amount;
    public readonly string $accountToken;
    public readonly string $subscriptionId;

    /** @var array<string, mixed> */
    public readonly array $additionalInfo;

    /**
     * @param array<string, mixed> $additionalInfo
     */
    public function __construct(
        string $partnerReferenceNo,
        Money $amount,
        string $accountToken,
        string $subscriptionId,
        array $additionalInfo = [],
    ) {
        if (trim($partnerReferenceNo) === '') {
            throw new InvalidArgumentException('partnerReferenceNo must not be empty');
        }
        if (trim($accountToken) === '') {
            throw new InvalidArgumentException('accountToken must not be empty');
        }
        if (trim($subscriptionId) === '') {
            throw new InvalidArgumentException('subscriptionId must not be empty');
        }

        $this->partnerReferenceNo = $partnerReferenceNo;
        $this->amount             = $amount;
        $this->accountToken       = $accountToken;
        $this->subscriptionId     = $subscriptionId;
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
            'subscriptionId'     => $this->subscriptionId,
        ];
        if ($this->additionalInfo !== []) {
            $body['additionalInfo'] = $this->additionalInfo;
        }
        return $body;
    }
}
