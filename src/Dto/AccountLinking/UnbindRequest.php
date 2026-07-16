<?php

declare(strict_types=1);

namespace ShopeePay\Dto\AccountLinking;

use InvalidArgumentException;

/**
 * Revoke an `accountToken`. Service code 09. Synchronous.
 *
 * After unbind succeeds, the token is no longer usable for new transactions.
 * In-flight transactions referencing it are not rolled back — they complete
 * (or fail) on their own. Refunds against already-completed payments do not
 * require an active binding.
 */
final class UnbindRequest
{
    public readonly string $accountToken;
    public readonly ?string $partnerReferenceNo;

    /**
     * svc 09 identifies the binding by EITHER accountToken OR partnerReferenceNo
     * (never both — see toArray()). accountToken is the reliable identifier and
     * is what toArray() prefers, so partnerReferenceNo is optional: pass just the
     * token and leave partnerReferenceNo null. At least one must be present.
     */
    public function __construct(string $accountToken, ?string $partnerReferenceNo = null)
    {
        $hasToken = trim($accountToken) !== '';
        $hasRef   = $partnerReferenceNo !== null && trim($partnerReferenceNo) !== '';

        if (!$hasToken && !$hasRef) {
            throw new InvalidArgumentException(
                'Provide accountToken or partnerReferenceNo to identify the binding to unbind',
            );
        }
        if ($partnerReferenceNo !== null && trim($partnerReferenceNo) === '') {
            throw new InvalidArgumentException(
                'partnerReferenceNo must be null or non-empty (not a whitespace string)',
            );
        }

        $this->accountToken       = $accountToken;
        $this->partnerReferenceNo = $partnerReferenceNo;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        # svc 09 (re-verified against the live sandbox 2026-07-02): identify the binding by
        # additionalInfo.accountToken OR top-level partnerReferenceNo — but NOT both. Sending
        # both trips "4000902 Invalid Mandatory Field {accountToken or partnerReferenceNo}".
        # Proven by probing the same token three ways: accountToken-only -> 2000900 Successful;
        # partnerReferenceNo-only -> 4040911; both -> 4000902. Prefer accountToken (the reliable
        # identifier); fall back to partnerReferenceNo only when no token is present.
        if (trim($this->accountToken) !== '') {
            return ['additionalInfo' => ['accountToken' => $this->accountToken]];
        }

        return ['partnerReferenceNo' => $this->partnerReferenceNo];
    }
}
