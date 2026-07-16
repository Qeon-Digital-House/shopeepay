<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AccountLinking;

/**
 * Result of an account-linking inquiry (svc 08).
 *
 * Sandbox-verified (2026-07-09): a successful inquiry (responseCode 2000800)
 * reports the binding state as additionalInfo.bindingStatus (1 = active), NOT a
 * top-level accountStatus string. accountStatus is kept for backward-compat but
 * is normally empty; isActive() reads bindingStatus.
 *
 * Sandbox-verified (2026-07-15): additionalInfo also carries the wallet balance
 * (additionalInfo.walletBalance, a decimal string in IDR e.g. "9000.00") and the
 * SPayLater info (additionalInfo.spaylaterInfo.availableBalance). Both are
 * surfaced here so callers can display the linked account's balance.
 */
final class InquiryResponse
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $responseCode,
        public readonly string $responseMessage,
        public readonly string $accountStatus,
        public readonly ?int $bindingStatus,
        public readonly ?string $referenceNo,
        public readonly ?string $partnerReferenceNo,
        public readonly array $raw,
        public readonly ?string $walletBalance = null,
        public readonly ?string $spaylaterAvailableBalance = null,
    ) {
    }

    public function isActive(): bool
    {
        // Primary signal: additionalInfo.bindingStatus === 1. Fall back to the
        // legacy accountStatus string if a future/other response carries it.
        if ($this->bindingStatus !== null) {
            return $this->bindingStatus === 1;
        }

        return strtoupper($this->accountStatus) === "ACTIVE";
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $additional = is_array($payload["additionalInfo"] ?? null) ? $payload["additionalInfo"] : [];
        $binding = $additional["bindingStatus"] ?? null;

        $spaylater = is_array($additional["spaylaterInfo"] ?? null) ? $additional["spaylaterInfo"] : [];

        return new self(
            responseCode:       is_string($payload["responseCode"] ?? null) ? $payload["responseCode"] : "",
            responseMessage:    is_string($payload["responseMessage"] ?? null) ? $payload["responseMessage"] : "",
            accountStatus:      is_string($payload["accountStatus"] ?? null) ? $payload["accountStatus"] : "",
            bindingStatus:      is_numeric($binding) ? (int) $binding : null,
            referenceNo:        is_string($payload["referenceNo"] ?? null) ? $payload["referenceNo"] : null,
            partnerReferenceNo: is_string($payload["partnerReferenceNo"] ?? null) ? $payload["partnerReferenceNo"] : null,
            raw:                $payload,
            // walletBalance: decimal string in IDR (e.g. "9000.00"); null when absent.
            walletBalance:      is_scalar($additional["walletBalance"] ?? null) ? (string) $additional["walletBalance"] : null,
            spaylaterAvailableBalance: is_scalar($spaylater["availableBalance"] ?? null) ? (string) $spaylater["availableBalance"] : null,
        );
    }
}