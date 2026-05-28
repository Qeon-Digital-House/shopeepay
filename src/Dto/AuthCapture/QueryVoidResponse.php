<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AuthCapture;

/**
 * Snapshot of a void's status. Voids are synchronous, so this response
 * is normally either "00" (succeeded) or "06" (failed) — but the broader
 * SNAP BI taxonomy is exposed in case the gateway returns a transient
 * pending state.
 *
 *   00 — Success (auth released)
 *   03 — Pending (rare — gateway-internal hiccup)
 *   06 — Failed
 *   07 — Not found
 */
final class QueryVoidResponse
{
    public const STATUS_SUCCESS   = '00';
    public const STATUS_PENDING   = '03';
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
