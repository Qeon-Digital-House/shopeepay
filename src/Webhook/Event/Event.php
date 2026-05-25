<?php

declare(strict_types=1);

namespace ShopeePay\Webhook\Event;

/**
 * Common shape every ShopeePay webhook event carries.
 *
 * Service-code dispatch + status interpretation happens in EventFactory.
 * Once an Event is in your hands, you know:
 *   - which service issued it,
 *   - whether the latest transaction status is success (`00`) or not,
 *   - the partner-side reference (your order id, if the gateway received it),
 *   - the ShopeePay-side reference,
 *   - and the raw decoded payload for anything subclass-specific.
 *
 * Subclasses do not add fields in v1 — they exist so callers can branch on
 * `instanceof` and get IDE autocomplete. Adding fields later is additive.
 */
abstract class Event
{
    /** SNAP BI's "success" sentinel for `latestTransactionStatus`. */
    public const STATUS_SUCCESS = '00';

    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $serviceCode,
        public readonly string $latestTransactionStatus,
        public readonly string $transactionStatusDesc,
        public readonly ?string $originalReferenceNo,
        public readonly ?string $originalPartnerReferenceNo,
        public readonly array $raw,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->latestTransactionStatus === self::STATUS_SUCCESS;
    }
}
