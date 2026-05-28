<?php

declare(strict_types=1);

/**
 * Example 03 — Subscription / recurring debit (svc 54 / 55 / 58, notify 52).
 *
 * Subscription charges share the create endpoint path with Link & Pay
 * (`/v1.1/debit/payment-host-to-host`). The gateway disambiguates them
 * by the presence of `subscriptionId` in the body.
 *
 * The `subscriptionId` itself is provisioned by an out-of-band
 * subscription-setup step (not covered by v1) — typically your billing
 * system stores it alongside the accountToken at signup time. For this
 * example, set SHOPEEPAY_SUBSCRIPTION_ID to a known value from sandbox.
 *
 * Notify channel — completion of a subscription charge arrives on svc 52
 * (NOT svc 56). See example 05 for the receiver.
 */

require __DIR__ . '/_bootstrap.php';

use ShopeePay\Dto\Common\Money;
use ShopeePay\Dto\Subscription\CheckStatusRequest;
use ShopeePay\Dto\Subscription\CreatePaymentRequest;
use ShopeePay\Dto\Subscription\RefundRequest;
use ShopeePay\Exception\ApiException;

$svc            = shopeepay_example_bootstrap();
$subscription   = $svc['subscription'];
$accountToken   = (string) (getenv('SHOPEEPAY_ACCOUNT_TOKEN') ?: '');
$subscriptionId = (string) (getenv('SHOPEEPAY_SUBSCRIPTION_ID') ?: '');

if ($accountToken === '' || $subscriptionId === '') {
    fwrite(STDERR, "Set SHOPEEPAY_ACCOUNT_TOKEN and SHOPEEPAY_SUBSCRIPTION_ID before running.\n");
    exit(0);
}

// 1) Initiate the recurring charge attempt.
$chargeRef = 'EX03-CHARGE-' . bin2hex(random_bytes(4));

try {
    $charge = $subscription->create(new CreatePaymentRequest(
        partnerReferenceNo: $chargeRef,
        amount:             new Money('99000.00'),    // 99,000 IDR monthly
        accountToken:       $accountToken,
        subscriptionId:     $subscriptionId,
    ));
} catch (ApiException $e) {
    // Common failure: 4025400 = insufficient funds. Your dunning logic
    // (retry tomorrow, notify the customer, etc.) reads $e->responseCode.
    echo "Charge failed: {$e->getMessage()}\n";
    exit(1);
}

echo "Charge queued. ShopeePay ref: {$charge->referenceNo}  partner ref: {$chargeRef}\n";
echo "(Subscription debits often skip the webRedirectUrl — empty string is normal.)\n\n";

// 2) Poll for terminal status — same cadence as Link & Pay.
$schedule = array_merge(array_fill(0, 20, 5), array_fill(0, 6, 5 * 60));

foreach ($schedule as $i => $delay) {
    sleep($delay);

    $status = $subscription->checkStatus(new CheckStatusRequest(
        originalReferenceNo: $charge->referenceNo,
    ));

    echo "Attempt " . ($i + 1) . ": {$status->latestTransactionStatus} ({$status->transactionStatusDesc})\n";

    if ($status->isTerminal()) {
        if ($status->isSuccess()) {
            echo "Charge succeeded.\n";
        } else {
            echo "Charge ended non-success — surface to your dunning logic.\n";
            exit(0);
        }
        break;
    }
}

// 3) (Optional) refund — e.g. customer downgraded mid-cycle.
echo "\n3) Refunding to show the refund path…\n";

$refund = $subscription->refund(new RefundRequest(
    refundAmount:        new Money('99000.00'),
    partnerRefundNo:     'EX03-REFUND-' . bin2hex(random_bytes(4)),
    originalReferenceNo: $charge->referenceNo,
    subscriptionId:      $subscriptionId,
    reason:              'plan downgrade — prorated refund',
));

echo "Refund queued: refundNo={$refund->refundNo}\n";
echo "Settlement notify will arrive on svc 52 with a refund-shape body —\n";
echo "EventFactory routes it to RefundCompleted. See example 05.\n";
