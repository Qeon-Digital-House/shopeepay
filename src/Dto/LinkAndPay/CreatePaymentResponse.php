<?php

declare(strict_types=1);

namespace ShopeePay\Dto\LinkAndPay;

/**
 * Result of a Link & Pay create call (svc 54). The transaction is in a
 * pending state until the caller redirects the user to `webRedirectUrl`
 * and they confirm — at which point ShopeePay sends a notify webhook
 * (svc 56) and `checkStatus()` will start returning a terminal status.
 *
 * `referenceNo` is ShopeePay's id for the transaction. `webRedirectUrl`
 * is what to put in front of the user; do not log it as it can contain
 * a one-time token in the query string.
 */
final class CreatePaymentResponse
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $responseCode,
        public readonly string $responseMessage,
        public readonly string $webRedirectUrl,
        public readonly ?string $referenceNo,
        public readonly ?string $partnerReferenceNo,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            responseCode:       is_string($payload['responseCode'] ?? null) ? $payload['responseCode'] : '',
            responseMessage:    is_string($payload['responseMessage'] ?? null) ? $payload['responseMessage'] : '',
            webRedirectUrl:     is_string($payload['webRedirectUrl'] ?? null) ? $payload['webRedirectUrl'] : '',
            referenceNo:        is_string($payload['referenceNo'] ?? null) ? $payload['referenceNo'] : null,
            partnerReferenceNo: is_string($payload['partnerReferenceNo'] ?? null) ? $payload['partnerReferenceNo'] : null,
            raw:                $payload,
        );
    }
}
