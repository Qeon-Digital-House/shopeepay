<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AccountLinking;

use InvalidArgumentException;

/**
 * Inputs for `/v1.0/get-auth-code` (svc 10). Used two ways:
 *
 *   - `AccountLinkingService::getAuthCode()` — a signed server-to-server GET
 *     that returns the `authCode` directly in its body (responseCode 2001000).
 *   - `AccountLinkingService::buildAuthCodeUrl()` — builds the URL the
 *     caller appends the authCode to / redirects the user's browser to.
 *
 * Either way, the user grants consent inside ShopeePay; ShopeePay then
 * redirects back to `redirectUrl` with `authCode` and `state` in the query
 * string, and that code is exchanged via `bind()`.
 *
 * `state` is the standard OAuth CSRF token. The caller MUST:
 *   1. Generate a random 32-char hex string (use the `generateState()` helper).
 *   2. Persist it in their session.
 *   3. On redirect return, compare the incoming `state` to the persisted one
 *      and reject the request on mismatch.
 *
 * `authCode` expires 30 minutes after issuance. The caller must exchange it
 * via `AccountLinkingService::bind()` before then, else the gateway returns
 * a `4030700`-class error.
 */
final class GetAuthCodeRequest
{
    /** Max length per SNAP BI spec; longer values are rejected by the gateway. */
    private const STATE_MAX_LENGTH = 32;

    public readonly string $redirectUrl;
    public readonly string $state;
    public readonly ?string $partnerReferenceNo;
    public readonly ?string $merchantId;

    /** @var list<string> */
    public readonly array $scopes;

    /**
     * @param ?string      $partnerReferenceNo  Optional. SNAP BI does not
     *                                          require this on /v1.0/get-auth-code;
     *                                          omitted from the URL when null.
     * @param list<string> $scopes              Permission scopes to request
     *                                          (e.g. `['ACCOUNT_BINDING']`).
     *                                          Pass an empty list to send no
     *                                          `scopes` param.
     */
    public function __construct(
        string $redirectUrl,
        string $state,
        ?string $partnerReferenceNo = null,
        ?string $merchantId = null,
        array $scopes = [],
    ) {
        if (trim($redirectUrl) === '') {
            throw new InvalidArgumentException('redirectUrl must not be empty');
        }
        if (filter_var($redirectUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(sprintf(
                'redirectUrl must be a valid URL, got %s',
                json_encode($redirectUrl),
            ));
        }
        if (trim($state) === '') {
            throw new InvalidArgumentException('state must not be empty');
        }
        if (strlen($state) > self::STATE_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'state must be ≤%d chars, got %d',
                self::STATE_MAX_LENGTH,
                strlen($state),
            ));
        }
        if ($partnerReferenceNo !== null && trim($partnerReferenceNo) === '') {
            throw new InvalidArgumentException(
                'partnerReferenceNo must be null or non-empty (not a whitespace string)',
            );
        }
        foreach ($scopes as $i => $scope) {
            if (!is_string($scope) || trim($scope) === '') {
                throw new InvalidArgumentException(sprintf(
                    'scopes[%d] must be a non-empty string',
                    $i,
                ));
            }
        }

        $this->redirectUrl        = $redirectUrl;
        $this->state              = $state;
        $this->partnerReferenceNo = $partnerReferenceNo;
        $this->merchantId         = $merchantId;
        $this->scopes             = array_values($scopes);
    }

    /**
     * Generate a CSRF-safe state token (16 random bytes → 32 hex chars).
     * Match length cap on STATE_MAX_LENGTH.
     */
    public static function generateState(): string
    {
        return bin2hex(random_bytes(16));
    }
}
