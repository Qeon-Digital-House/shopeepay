<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AuthCapture;

/**
 * Snapshot of a capture's status. Status taxonomy is the SNAP BI
 * standard (see [[QueryAuthResponse]] for the shared base set). Captures
 * settle asynchronously — final outcome arrives via the `AuthCaptured`
 * webhook (notify); this query lets callers reconcile out-of-band.
 *
 *   00 — Success (settled)
 *   01 — Initiated (capture queued, not yet settled)
 *   03 — Pending
 *   04 — Refunded (capture has been refunded — terminal)
 *   06 — Failed
 *   07 — Not found
 *
 * `isSuccess()` returns true only for "00". `isTerminal()` returns true
 * once the capture can no longer change state.
 */
final class QueryCaptureResponse
{
    public const STATUS_SUCCESS   = '00';
    public const STATUS_INITIATED = '01';
    public const STATUS_PENDING   = '03';
    public const STATUS_REFUNDED  = '04';
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
