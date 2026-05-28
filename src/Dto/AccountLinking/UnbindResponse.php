<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AccountLinking;

final class UnbindResponse
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $responseCode,
        public readonly string $responseMessage,
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
            referenceNo:        is_string($payload['referenceNo'] ?? null) ? $payload['referenceNo'] : null,
            partnerReferenceNo: is_string($payload['partnerReferenceNo'] ?? null) ? $payload['partnerReferenceNo'] : null,
            raw:                $payload,
        );
    }
}
