<?php

declare(strict_types=1);

/**
 * Example 02 — Link & Pay (svc 54 / 55 / 58).
 *
 * Flow:
 *   1. create() — initiate a debit-host-to-host payment, get a webRedirectUrl
 *      the user must visit to confirm in ShopeePay.
 *   2. poll checkStatus() until terminal — the SDK does NOT auto-poll
 *      (design doc, "Things explicitly OUT of scope"). The pattern below
 *      (5s × 20 then 5m × 6) is the suggested cadence; tune as needed.
 *   3. (optional) refund() if you need to reverse the charge.
 *
 * Requires a prior accountToken from example 01. Pass it via SHOPEEPAY_ACCOUNT_TOKEN.
 *
 * Polling-vs-webhooks — many integrations stop polling once the notify
 * webhook (svc 56) arrives. The example shows polling for illustration; in
 * production, treat polling as a fallback when the webhook is late.
 */

require __DIR__ . '/_bootstrap.php';

use ShopeePay\Dto\Common\Money;
use ShopeePay\Dto\LinkAndPay\CheckStatusRequest;
use ShopeePay\Dto\LinkAndPay\CheckStatusResponse;
use ShopeePay\Dto\LinkAndPay\CreatePaymentRequest;
use ShopeePay\Dto\LinkAndPay\RefundRequest;
use ShopeePay\Exception\ApiException;

$svc          = shopeepay_example_bootstrap();
$linkAndPay   = $svc['linkAndPay'];
$accountToken = (string) (getenv('SHOPEEPAY_ACCOUNT_TOKEN') ?: '');

if ($accountToken === '') {
    fwrite(STDERR, "SHOPEEPAY_ACCOUNT_TOKEN is not set. Run example 01 first to bind an account.\n");
    exit(0);
}

// 1) Create the payment.
$orderRef = 'EX02-ORDER-' . bin2hex(random_bytes(4));

try {
    $payment = $linkAndPay->create(new CreatePaymentRequest(
        partnerReferenceNo: $orderRef,
        amount:             new Money('15000.00'),       // 15,000 IDR
        accountToken:       $accountToken,
        additionalInfo:     ['note' => 'Example 02 — Link & Pay'],
    ));
} catch (ApiException $e) {
    echo "create() failed: {$e->getMessage()}\n";
    exit(1);
}

echo "Payment created. Send the user here to confirm:\n  {$payment->webRedirectUrl}\n";
echo "ShopeePay ref: {$payment->referenceNo}  partner ref: {$orderRef}\n\n";

// 2) Poll for terminal status — 5s × 20 (fast phase) then 5m × 6 (slow phase).
//    Stop early once isTerminal() returns true.
$schedule = array_merge(
    array_fill(0, 20, 5),       // 20 attempts, 5s apart  → ~1m40s
    array_fill(0, 6, 5 * 60),   //  6 attempts, 5m apart  → 30m total
);

$status = null;
foreach ($schedule as $i => $delay) {
    sleep($delay);

    try {
        $status = $linkAndPay->checkStatus(new CheckStatusRequest(
            originalReferenceNo:        $payment->referenceNo,
            originalPartnerReferenceNo: $orderRef,
        ));
    } catch (ApiException $e) {
        echo "checkStatus() error on attempt " . ($i + 1) . ": {$e->getMessage()}\n";
        continue;
    }

    echo "Attempt " . ($i + 1) . ": status={$status->latestTransactionStatus} ({$status->transactionStatusDesc})\n";

    if ($status->isTerminal()) {
        break;
    }
}

if ($status === null || !$status->isTerminal()) {
    echo "Did not reach terminal within polling window. Webhook (svc 56) will\n";
    echo "still fire when the user completes. See example 05 for the receiver.\n";
    exit(0);
}

if (!$status->isSuccess()) {
    echo "Payment ended non-success: {$status->latestTransactionStatus}\n";
    exit(0);
}

echo "Payment success.\n\n";

// 3) (Optional) refund. Comment out if you want to keep the test charge.
echo "3) Refunding to demonstrate the refund flow…\n";
$refund = $linkAndPay->refund(new RefundRequest(
    refundAmount:        new Money('15000.00'),
    partnerRefundNo:     'EX02-REFUND-' . bin2hex(random_bytes(4)),
    originalReferenceNo: $payment->referenceNo,
    reason:              'example cleanup',
));

if ($refund->refundAmount !== null) {
    echo "Refunded {$refund->refundAmount->value} {$refund->refundAmount->currency}";
    echo " (refundNo={$refund->refundNo})\n";
} else {
    echo "Refund queued (refundNo={$refund->refundNo}); settlement notify arrives on svc 56.\n";
}

// Hint at the terminal-state enum so callers see what they can branch on.
unset($svc);
$_terminalStatesForReference = [
    CheckStatusResponse::STATUS_SUCCESS,
    CheckStatusResponse::STATUS_REFUNDED,
    CheckStatusResponse::STATUS_CANCELLED,
    CheckStatusResponse::STATUS_FAILED,
    CheckStatusResponse::STATUS_NOT_FOUND,
];
