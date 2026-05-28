<?php

declare(strict_types=1);

namespace ShopeePay\Dto\LinkAndPay;

/**
 * Snapshot of a transaction's status. `latestTransactionStatus` is the
 * SNAP BI two-digit code; "00" is success, anything else is non-terminal
 * or failed. `transactionStatusDesc` is the human-readable label.
 *
 * Known status values (not exhaustive):
 *   00 — Success
 *   01 — Initiated (created but not yet confirmed by user)
 *   02 — Paying (user confirming inside ShopeePay)
 *   03 — Pending
 *   04 — Refunded
 *   05 — Cancelled
 *   06 — Failed
 *   07 — Not found
 *
 * Polling cadence (per design doc, "Things explicitly OUT of scope"):
 * the SDK does NOT auto-poll. Callers implement their own loop — see
 * `examples/02-link-and-pay.php` (5s × 20 then 5m × 6 is the suggested
 * pattern).
 */
final class CheckStatusResponse
{
    public const STATUS_SUCCESS   = '00';
    public const STATUS_INITIATED = '01';
    public const STATUS_PAYING    = '02';
    public const STATUS_PENDING   = '03';
    public const STATUS_REFUNDED  = '04';
    public const STATUS_CANCELLED = '05';
    public const STATUS_FAILED    = '06';
    public const STATUS_NOT_FOUND = '07';

    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $responseCode,
        public readonly string $responseMessage,
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

    public function isTerminal(): bool
    {
        return in_array(
            $this->latestTransactionStatus,
            [
                self::STATUS_SUCCESS,
                self::STATUS_REFUNDED,
                self::STATUS_CANCELLED,
                self::STATUS_FAILED,
                self::STATUS_NOT_FOUND,
            ],
            true,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            responseCode:               is_string($payload['responseCode'] ?? null) ? $payload['responseCode'] : '',
            responseMessage:            is_string($payload['responseMessage'] ?? null) ? $payload['responseMessage'] : '',
            latestTransactionStatus:    is_string($payload['latestTransactionStatus'] ?? null) ? $payload['latestTransactionStatus'] : '',
            transactionStatusDesc:      is_string($payload['transactionStatusDesc'] ?? null) ? $payload['transactionStatusDesc'] : '',
            originalReferenceNo:        is_string($payload['originalReferenceNo'] ?? null) ? $payload['originalReferenceNo'] : null,
            originalPartnerReferenceNo: is_string($payload['originalPartnerReferenceNo'] ?? null) ? $payload['originalPartnerReferenceNo'] : null,
            raw:                        $payload,
        );
    }
}
