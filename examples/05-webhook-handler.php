<?php

declare(strict_types=1);

/**
 * Example 05 — Webhook receiver.
 *
 * ShopeePay POSTs async notifications to your callback URL. The SDK
 * verifies the RSA-SHA256 signature against ShopeePay's public key,
 * checks the replay window (5min default), and returns a typed Event.
 *
 * Caller responsibility (REQUIRED, easy to get wrong):
 *
 *   - Pass the RAW request body string, NOT a PSR-7 stream. Some
 *     middleware consumes the stream before you reach this point; if so,
 *     buffer the body BEFORE middleware runs and pass the buffered string.
 *     The signature is computed over the exact bytes ShopeePay sent.
 *
 *   - Pass the path ONLY (no scheme/host) — `$request->getUri()->getPath()`.
 *     The signature base is `POST:{path}:{bodyHash}:{timestamp}`.
 *
 *   - Handle the `default` arm of the match — UnknownEvent is what the
 *     SDK returns for service codes it doesn't recognize (forward-compat).
 *     A missing default arm silently drops new event types.
 *
 * The example can be driven two ways:
 *   1. As a standalone CLI script processing a fixture file (default).
 *      Useful for local testing without an HTTP server.
 *   2. Drop the dispatch block into your framework's webhook route
 *      (Laravel, Symfony, plain PHP). Comments below show the shape.
 */

require __DIR__ . '/_bootstrap.php';

use ShopeePay\Exception\SignatureException;
use ShopeePay\Exception\WebhookException;
use ShopeePay\Webhook\Event\AuthCaptured;
use ShopeePay\Webhook\Event\PaymentCompleted;
use ShopeePay\Webhook\Event\PaymentFailed;
use ShopeePay\Webhook\Event\RefundCompleted;
use ShopeePay\Webhook\Event\SubscriptionPaymentCompleted;
use ShopeePay\Webhook\Event\SubscriptionPaymentFailed;
use ShopeePay\Webhook\Event\UnknownEvent;

$svc       = shopeepay_example_bootstrap();
$verifier  = $svc['webhooks'];

// ── In a real framework you'd pull these out of the incoming request: ──
//
//   $method    = $request->getMethod();
//   $path      = $request->getUri()->getPath();      // path only
//   $rawBody   = (string) $request->getBody();       // ← BUFFER BEFORE THIS
//   $signature = $request->getHeaderLine('X-SIGNATURE');
//   $timestamp = $request->getHeaderLine('X-TIMESTAMP');
//
// For the standalone CLI demo we read a JSON fixture + a JSON sidecar
// holding the signature/timestamp that we'd otherwise read from headers.

$fixturePath   = $argv[1] ?? __DIR__ . '/../tests/fixtures/notify-link-and-pay.json';
$headerPath    = $argv[2] ?? null;          // {"X-SIGNATURE": "...", "X-TIMESTAMP": "..."}

if (!is_file($fixturePath)) {
    fwrite(STDERR, "fixture not found: $fixturePath\n");
    fwrite(STDERR, "Usage: php examples/05-webhook-handler.php <body.json> <headers.json>\n");
    exit(1);
}

$rawBody = (string) file_get_contents($fixturePath);
if ($headerPath !== null && is_file($headerPath)) {
    $headers   = (array) json_decode((string) file_get_contents($headerPath), true);
    $signature = is_string($headers['X-SIGNATURE'] ?? null) ? $headers['X-SIGNATURE'] : '';
    $timestamp = is_string($headers['X-TIMESTAMP'] ?? null) ? $headers['X-TIMESTAMP'] : '';
} else {
    fwrite(STDERR, "No headers file supplied; the example will fail signature verification.\n");
    fwrite(STDERR, "This is expected — it demonstrates the SignatureException path.\n");
    $signature = '';
    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))
                    ->format('Y-m-d\TH:i:sP');
}

// ── Verify + dispatch ──────────────────────────────────────────────────

try {
    $event = $verifier->parse(
        method:          'POST',
        callbackPath:    '/your-app/shopeepay/webhook',
        rawBody:         $rawBody,
        base64Signature: $signature,
        timestamp:       $timestamp,
    );
} catch (SignatureException $e) {
    // Signature mismatch, replay window violation, or openssl error.
    // Respond 401 to ShopeePay so it retries.
    fwrite(STDERR, "Signature verification failed: {$e->getMessage()}\n");
    http_response_code(401);
    exit(1);
} catch (WebhookException $e) {
    // Malformed body or non-POST method. 400 — don't retry, the payload
    // is broken on the gateway side and a retry will just fail the same way.
    fwrite(STDERR, "Webhook rejected: {$e->getMessage()}\n");
    http_response_code(400);
    exit(1);
}

// Branch on the event type. Note the `UnknownEvent` arm — DO NOT REMOVE.
// New ShopeePay svc codes that the SDK doesn't recognize land here, and
// without the default arm they would silently drop.
match (true) {
    $event instanceof PaymentCompleted             => printf("Link & Pay paid: ref=%s\n",            $event->originalReferenceNo  ?? '?'),
    $event instanceof PaymentFailed                => printf("Link & Pay failed: ref=%s desc=%s\n",  $event->originalReferenceNo  ?? '?', $event->transactionStatusDesc),
    $event instanceof SubscriptionPaymentCompleted => printf("Subscription charged: ref=%s\n",       $event->originalReferenceNo  ?? '?'),
    $event instanceof SubscriptionPaymentFailed    => printf("Subscription failed: ref=%s desc=%s\n",$event->originalReferenceNo  ?? '?', $event->transactionStatusDesc),
    $event instanceof AuthCaptured                 => printf("Capture settled: ref=%s status=%s\n",  $event->originalReferenceNo  ?? '?', $event->latestTransactionStatus),
    $event instanceof RefundCompleted              => printf("Refund settled: ref=%s\n",             $event->originalReferenceNo  ?? '?'),
    $event instanceof UnknownEvent                 => fwrite(STDERR, sprintf(
        "WARNING: unknown svc=%s status=%s — upgrade the SDK or branch on \$event->raw to handle it.\n",
        $event->serviceCode,
        $event->latestTransactionStatus,
    )),
};

// Always 200 once we've successfully decoded and dispatched. The gateway
// retries any non-2xx response, including 5xx — so for transient errors
// in your own handlers, log + 200 is usually safer than letting the
// exception propagate and triggering retries that won't help.
http_response_code(200);
