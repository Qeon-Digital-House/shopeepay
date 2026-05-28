<?php

declare(strict_types=1);

namespace ShopeePay\Exception;

/**
 * The gateway accepted the request but returned a non-success responseCode,
 * OR exhausted retry-on-auth-failure (two consecutive 401 / 4011xxx).
 *
 * Carries the parsed response fields so callers can branch on them without
 * re-parsing.
 */
final class ApiException extends ShopeePayException
{
    public function __construct(
        public readonly string $responseCode,
        public readonly string $responseMessage,
        public readonly ?string $referenceNo = null,
        public readonly ?string $partnerReferenceNo = null,
    ) {
        parent::__construct(sprintf('%s: %s', $responseCode, $responseMessage));
    }
}
