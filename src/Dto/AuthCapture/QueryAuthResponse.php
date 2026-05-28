<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AuthCapture;

/**
 * Snapshot of an authorization's status. The two-digit status codes
 * follow the same SNAP BI taxonomy as `LinkAndPay\CheckStatusResponse`,
 * with auth-specific terminal states added:
 *   00 — Success (auth held)
 *   01 — Initiated
 *   02 — Paying (user confirming inside ShopeePay)
 *   03 — Pending
 *   04 — Captured (auth has been settled — terminal for this query)
 *   05 — Cancelled / Voided
 *   06 — Failed
 *   07 — Not found
 *   08 — Expired (validUpTo elapsed without capture)
 *
 * `isSuccess()` returns true only for "00" (auth is held and ready to
 * capture). `isTerminal()` returns true for any state the auth can no
 * longer leave — capture/void/cancel/fail/notfound/expire all qualify.
 * Callers should stop polling once `isTerminal()` is true.
 */
final class QueryAuthResponse
{
    public const STATUS_SUCCESS   = '00';
    public const STATUS_INITIATED = '01';
    public const STATUS_PAYING    = '02';
    public const STATUS_PENDING   = '03';
    public const STATUS_CAPTURED  = '04';
    public const STATUS_CANCELLED = '05';
    public const STATUS_FAILED    = '06';
    public const STATUS_NOT_FOUND = '07';
    public const STATUS_EXPIRED   = '08';

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
                self::STATUS_CAPTURED,
                self::STATUS_CANCELLED,
                self::STATUS_FAILED,
                self::STATUS_NOT_FOUND,
                self::STATUS_EXPIRED,
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
