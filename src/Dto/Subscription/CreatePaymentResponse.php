<?php

declare(strict_types=1);

namespace ShopeePay\Dto\Subscription;

/**
 * Result of a Subscription recurring-charge create call (svc 54). For
 * subscription debits, ShopeePay does NOT typically prompt the user
 * (the binding was authorized at subscription-setup time), so
 * `webRedirectUrl` is often empty. Caller should rely on the notify
 * webhook (svc 52) for terminal state, not the response.
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
