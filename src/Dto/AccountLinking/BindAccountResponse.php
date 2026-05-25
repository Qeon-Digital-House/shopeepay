<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AccountLinking;

/**
 * Successful response from the bind endpoint.
 *
 * The `accountToken` is what subsequent Link & Pay / Subscription /
 * Auth & Capture calls reference as the user's bound wallet. Treat it as a
 * secret — it goes into `LogScrubber`'s redaction list.
 *
 * `raw` keeps the full decoded payload so callers can read fields we haven't
 * surfaced as properties (e.g. additional balance hints in `additionalInfo`).
 */
final class BindAccountResponse
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $responseCode,
        public readonly string $responseMessage,
        public readonly string $accountToken,
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
            responseCode:       self::str($payload, 'responseCode'),
            responseMessage:    self::str($payload, 'responseMessage'),
            accountToken:       self::str($payload, 'accountToken'),
            referenceNo:        self::strOrNull($payload, 'referenceNo'),
            partnerReferenceNo: self::strOrNull($payload, 'partnerReferenceNo'),
            raw:                $payload,
        );
    }

    /** @param array<string, mixed> $a */
    private static function str(array $a, string $k): string
    {
        return is_string($a[$k] ?? null) ? $a[$k] : '';
    }

    /** @param array<string, mixed> $a */
    private static function strOrNull(array $a, string $k): ?string
    {
        return is_string($a[$k] ?? null) ? $a[$k] : null;
    }
}
