<?php

declare(strict_types=1);

/**
 * Example 04 — Auth & Capture (svc 63 / 65 / 67 / 69 + queries 64 / 66 / 68).
 *
 * Card-on-file pattern, four stages:
 *
 *   1. authorize() — reserve funds (svc 63). Returns referenceNo and
 *      sometimes a webRedirectUrl for user confirmation.
 *   2. capture()  — settle some or all of the reserved amount (svc 65).
 *      One partial capture per authorization is allowed; the gateway
 *      releases any unreserved balance back to the customer.
 *   3. void()     — release an un-captured authorization (svc 67).
 *      Must occur BEFORE capture. Use refund() to reverse a captured charge.
 *   4. refund()   — reverse a captured charge (svc 69). Posts to
 *      `/v1.0/auth/refund` (different path from debit refunds).
 *
 * The script demonstrates the "happy" branch (authorize → capture → refund).
 * The void branch is shown as a separate function so you can swap in.
 *
 * State-machine constraints are NOT enforced client-side — the gateway is
 * the source of truth and surfaces violations as `ApiException`.
 */

require __DIR__ . '/_bootstrap.php';

use ShopeePay\Dto\AuthCapture\AuthorizeRequest;
use ShopeePay\Dto\AuthCapture\CaptureRequest;
use ShopeePay\Dto\AuthCapture\QueryAuthRequest;
use ShopeePay\Dto\AuthCapture\QueryCaptureRequest;
use ShopeePay\Dto\AuthCapture\RefundRequest;
use ShopeePay\Dto\AuthCapture\VoidRequest;
use ShopeePay\Dto\Common\Money;
use ShopeePay\Exception\ApiException;

$svc          = shopeepay_example_bootstrap();
$ac           = $svc['authCapture'];
$accountToken = (string) (getenv('SHOPEEPAY_ACCOUNT_TOKEN') ?: '');

if ($accountToken === '') {
    fwrite(STDERR, "SHOPEEPAY_ACCOUNT_TOKEN is not set. Run example 01 first.\n");
    exit(0);
}

$authRef = 'EX04-AUTH-' . bin2hex(random_bytes(4));

// 1) Authorize — reserve 250,000 IDR for a hotel-style deposit. Auth holds
//    for 24h by default; pass validUpTo for up to 14 days.
try {
    $auth = $ac->authorize(new AuthorizeRequest(
        partnerReferenceNo: $authRef,
        amount:             new Money('250000.00'),
        accountToken:       $accountToken,
        validUpTo:          (new DateTimeImmutable('+24 hours', new DateTimeZone('Asia/Jakarta')))
                                ->format('Y-m-d\TH:i:s.vP'),
        additionalInfo:     ['note' => 'Example 04 — hotel deposit'],
    ));
} catch (ApiException $e) {
    echo "authorize() failed: {$e->getMessage()}\n";
    exit(1);
}

echo "Authorized {$auth->referenceNo} (partner {$authRef}).\n";
if ($auth->webRedirectUrl !== '') {
    echo "Send the user to confirm: {$auth->webRedirectUrl}\n";
    echo "(Then poll queryAuth() until status='00' before capturing.)\n";
}

// 1b) queryAuth — poll until the auth is held (status="00") and ready
//     to capture. Skip if your flow uses the async notify webhook (svc 65).
$authStatus = $ac->queryAuth(new QueryAuthRequest(originalReferenceNo: $auth->referenceNo));
echo "Auth status: {$authStatus->latestTransactionStatus} ({$authStatus->transactionStatusDesc})\n\n";

// 2) Capture — settle 180,000 IDR (partial). The remaining 70,000 is
//    released to the customer. **One partial capture per auth** — a second
//    attempt returns ApiException.
$captureRef = 'EX04-CAP-' . bin2hex(random_bytes(4));

try {
    $capture = $ac->capture(new CaptureRequest(
        captureAmount:       new Money('180000.00'),
        partnerReferenceNo:  $captureRef,
        originalReferenceNo: $auth->referenceNo,
    ));
} catch (ApiException $e) {
    echo "capture() failed: {$e->getMessage()}\n";
    exit(1);
}

echo "Captured {$capture->referenceNo} (partner {$captureRef}).\n";
if ($capture->capturedAmount !== null) {
    echo "Captured amount: {$capture->capturedAmount->value} {$capture->capturedAmount->currency}\n";
}

// 2b) queryCapture — capture settlement is async (svc 65 webhook). Poll
//     until terminal if you can't receive notifies.
$capStatus = $ac->queryCapture(new QueryCaptureRequest(originalReferenceNo: $capture->referenceNo));
echo "Capture status: {$capStatus->latestTransactionStatus} ({$capStatus->transactionStatusDesc})\n\n";

// 3) Refund — reverse the captured charge (svc 69).
$refund = $ac->refund(new RefundRequest(
    refundAmount:        new Money('180000.00'),
    partnerRefundNo:     'EX04-REFUND-' . bin2hex(random_bytes(4)),
    originalReferenceNo: $capture->referenceNo,
    reason:              'customer service goodwill',
));
echo "Refund queued: refundNo={$refund->refundNo}\n\n";

// ── Alternate flow: void instead of capture ────────────────────────────
//
// Run this branch when the customer cancels BEFORE you've captured. After
// a successful capture, void is rejected — use refund instead.
//
//   $void = $ac->void(new VoidRequest(
//       partnerReferenceNo:  'EX04-VOID-' . bin2hex(random_bytes(4)),
//       originalReferenceNo: $auth->referenceNo,
//       reason:              'customer cancelled before capture',
//   ));
//
// The void is synchronous — once the gateway returns 2006700, the held
// balance is released to the customer immediately.

unset($_unused);
$_referencesForDocOnly = [VoidRequest::class];
