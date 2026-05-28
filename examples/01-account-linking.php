<?php

declare(strict_types=1);

/**
 * Example 01 — Account Linking (svc 10 / 07 / 09 / 08).
 *
 * Demonstrates the consent dance:
 *
 *   1. SDK builds a get-auth-code URL for ShopeePay's consent screen.
 *   2. CALLER redirects the user's browser there. (Out-of-band step —
 *      shown here as a printed URL the user pastes into a browser.)
 *   3. ShopeePay redirects back to `redirectUrl?authCode=…&state=…`.
 *   4. CALLER verifies `state` matches the one they persisted (CSRF).
 *   5. SDK exchanges `authCode` for a long-lived `accountToken` via bind().
 *   6. SDK inquires status, then unbinds at the end.
 *
 * State persistence — in a real app you'd store the `state` token in your
 * session and verify it on redirect return. Here we print it instead and
 * the user pastes the returned authCode into the prompt.
 */

require __DIR__ . '/_bootstrap.php';

use ShopeePay\Dto\AccountLinking\BindAccountRequest;
use ShopeePay\Dto\AccountLinking\GetAuthCodeRequest;
use ShopeePay\Dto\AccountLinking\InquiryRequest;
use ShopeePay\Dto\AccountLinking\UnbindRequest;
use ShopeePay\Exception\ApiException;

$svc       = shopeepay_example_bootstrap();
$linking   = $svc['accountLinking'];

// Step 1 — build the authorization URL.
$state = GetAuthCodeRequest::generateState();      // 32-char hex; CSRF token
$ref   = 'EX01-LINK-' . bin2hex(random_bytes(4));

$authCodeUrl = $linking->buildAuthCodeUrl(new GetAuthCodeRequest(
    redirectUrl:        'https://your-app.example/shopeepay/callback',
    state:              $state,
    partnerReferenceNo: $ref,
));

echo "1) Send the user to this URL to grant consent:\n   $authCodeUrl\n\n";
echo "   Persist this state in your session for CSRF verification:\n   $state\n\n";

// Step 2/3/4 — out of band. The user grants consent and ShopeePay redirects
// them to your callback URL with `authCode` and `state` in the query string.
// Your callback handler MUST verify the `state` matches the persisted one
// before proceeding. For the example, prompt the user to paste the authCode.
echo "2) After consent, paste the authCode from the redirect URL: ";
$authCode = trim((string) fgets(STDIN));
if ($authCode === '') {
    echo "(no authCode supplied — stopping before bind)\n";
    exit(0);
}

// Step 5 — exchange authCode for accountToken (svc 07).
try {
    $bind = $linking->bind(new BindAccountRequest(
        authCode:           $authCode,
        partnerReferenceNo: 'EX01-BIND-' . bin2hex(random_bytes(4)),
    ));
} catch (ApiException $e) {
    // Common failure: 4030700-class — authCode expired (>30 min since issuance).
    echo "Bind failed: {$e->getMessage()}\n";
    exit(1);
}

echo "Bound: accountToken={$bind->accountToken} ref={$bind->referenceNo}\n\n";

// Step 6a — inquire account status (svc 08).
$inquiry = $linking->inquiry(new InquiryRequest(
    accountToken:       $bind->accountToken,
    partnerReferenceNo: 'EX01-INQUIRY-' . bin2hex(random_bytes(4)),
));
echo "Status: {$inquiry->accountStatus}"
   . ($inquiry->isActive() ? " (active — ready to charge)" : " (NOT active)")
   . "\n";

// Step 6b — unbind to clean up (svc 09). Optional; tokens persist until revoked.
$linking->unbind(new UnbindRequest(
    accountToken:       $bind->accountToken,
    partnerReferenceNo: 'EX01-UNBIND-' . bin2hex(random_bytes(4)),
));
echo "Unbound. Save the accountToken if you want to charge later — once\n";
echo "unbound, you'd need to re-run get-auth-code + bind to charge again.\n";
