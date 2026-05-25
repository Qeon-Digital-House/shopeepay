<?php

declare(strict_types=1);

namespace ShopeePay\Http;

use ShopeePay\Exception\ConfigException;

/**
 * Parses the 7-digit SNAP BI responseCode into HTTP / Service / Sub components.
 *
 *   HTTP(3) + Service(2) + Sub(2)
 *   2001000 = HTTP 200, service 10 (Account Linking — Get Auth Code), sub 00
 *   2005400 = HTTP 200, service 54 (Create Payment — used by both Link & Pay
 *             AND Subscription; disambiguate by endpoint path + DTO type, not
 *             by responseCode).
 *   4011000 = HTTP 401, service 10, sub 00 — token expired / unauthorized.
 *
 * Treat 200-class as success, anything else as failure.
 */
final class StatusCode
{
    public function __construct(
        public readonly int $http,
        public readonly int $service,
        public readonly int $sub,
        public readonly string $raw,
    ) {
    }

    /**
     * @throws ConfigException if $code is not exactly 7 digits.
     */
    public static function parse(string $code): self
    {
        if (!preg_match('/^\d{7}$/', $code)) {
            throw new ConfigException(sprintf(
                'responseCode must be exactly 7 digits, got %s',
                json_encode($code),
            ));
        }

        return new self(
            http:    (int) substr($code, 0, 3),
            service: (int) substr($code, 3, 2),
            sub:     (int) substr($code, 5, 2),
            raw:     $code,
        );
    }

    public function isSuccess(): bool
    {
        return $this->http >= 200 && $this->http < 300;
    }

    /**
     * Token-expiry signal in the response body. Triggers a single retry in
     * AccessTokenManager. Anything matching `4011xxx` qualifies — sub-code
     * is irrelevant for the retry decision.
     */
    public function isAuthFailure(): bool
    {
        return $this->http === 401 && $this->service === 10;
    }
}
