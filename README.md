# shopeepay-php

Unofficial PHP SDK for **ShopeePay** (SNAP BI). Framework-agnostic, typed DTOs,
PSR-18/17/16/3 throughout. PHP 8.1+.

Status: **v0.1.0 candidate** — 136 unit tests, PHPStan level 8 clean. Not yet
on Packagist; pending sandbox-endpoint confirmation (see build-order step 11).

## Why this exists

There is no official PHP SDK from ShopeePay. Every merchant ends up
re-implementing asymmetric RSA-SHA256 signing for access tokens, symmetric
HMAC-SHA512 signing for transactions (with the body-hash inside the
stringToSign), webhook signature verification, response-code parsing, and the
processing-state polling pattern. Most get at least one of these wrong.

This SDK fills that gap with the four flows merchants actually need.

## Flows in v1

| Flow            | Operations                                              |
| --------------- | ------------------------------------------------------- |
| Account Linking | `buildAuthCodeUrl` → `bind` → `inquiry` → `unbind`      |
| Link & Pay      | `create` → `checkStatus` → `refund`                     |
| Subscription    | `create` → `checkStatus` → `refund`                     |
| Auth & Capture  | `authorize` → `capture` / `void` → `refund` + 3 queries |
| Webhook         | RSA-SHA256 verify + 5-min replay window → typed Event   |

## Install

```bash
composer require rrq/shopeepay-php
```

You also need a PSR-18 HTTP client and a PSR-16 cache. Any implementation
works; common picks:

```bash
composer require symfony/http-client nyholm/psr7 symfony/cache
```

## Quickstart

The SDK is framework-agnostic — wire it up yourself with the PSR
implementations you already use. (A Laravel companion package is on the v2
roadmap; see [TODOS.md](TODOS.md).)

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use ShopeePay\Config;
use ShopeePay\Environment;
use ShopeePay\Http\{AccessTokenManager, HeaderBuilder, Signer, Transport};
use ShopeePay\Service\{AccountLinkingService, AuthCaptureService, LinkAndPayService, SubscriptionService};
use ShopeePay\Webhook\{EventFactory, Verifier};
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

$psr17 = new Psr17Factory();

$config = new Config(
    clientId:           getenv('SHOPEEPAY_CLIENT_ID'),
    clientSecret:       getenv('SHOPEEPAY_SECRET_KEY'),
    privateKey:         getenv('SHOPEEPAY_PRIVATE_KEY'),       // PEM string
    shopeepayPublicKey: getenv('SHOPEEPAY_PUBLIC_KEY'),        // PEM string
    merchantId:         getenv('SHOPEEPAY_SUBS_MERCHANT_ID'),
    httpClient:         \Http\Discovery\Psr18ClientDiscovery::find(),
    requestFactory:     $psr17,
    streamFactory:      $psr17,
    cache:              new Psr16Cache(new ArrayAdapter()),    // use Redis in prod
    environment:        Environment::SANDBOX,
);

$signer        = new Signer();
$headerBuilder = new HeaderBuilder($config, $signer);
$atm           = new AccessTokenManager($config, $headerBuilder);
$transport     = new Transport($config, $headerBuilder, $atm);

$accountLinking = new AccountLinkingService($config, $transport);
$linkAndPay     = new LinkAndPayService($transport);
$subscription   = new SubscriptionService($transport);
$authCapture    = new AuthCaptureService($transport);
$webhooks       = new Verifier($config, $signer, new EventFactory());
```

`Config` validates everything at construction — bad PEMs, empty
client-id, sub-60-second token TTLs all throw `ConfigException` at boot
rather than at first request. Pass PEMs as **strings**, not file paths
(containerized deploys reading from secret managers never need to
materialize a key file on disk).

See [`.env.example`](.env.example) for the conventional environment-variable
names and [`examples/_bootstrap.php`](examples/_bootstrap.php) for a
copy-paste-runnable bootstrap that reads them.

## Per-flow guide

Each flow has a runnable example under [`examples/`](examples/) with the
full state-machine walkthrough. The snippets below show the minimum
surface area.

### Account Linking

A three-stage consent dance: the SDK builds a URL, the user grants consent
inside ShopeePay, ShopeePay redirects back to your callback with an
`authCode`, and you exchange it for a long-lived `accountToken`.

```php
use ShopeePay\Dto\AccountLinking\{BindAccountRequest, GetAuthCodeRequest, InquiryRequest, UnbindRequest};

// 1. Build the consent URL and redirect the user.
$state = GetAuthCodeRequest::generateState();   // store in session for CSRF check
$url   = $accountLinking->buildAuthCodeUrl(new GetAuthCodeRequest(
    redirectUrl:        'https://your-app.example/shopeepay/callback',
    state:              $state,
    partnerReferenceNo: 'LINK-' . bin2hex(random_bytes(4)),
));
// header("Location: $url");

// 2. On callback: verify state matches, then exchange authCode for accountToken.
$bound = $accountLinking->bind(new BindAccountRequest(
    authCode:           $_GET['authCode'],
    partnerReferenceNo: 'BIND-' . bin2hex(random_bytes(4)),
));
// Persist $bound->accountToken — treat it as a secret.

// 3. (Optional) confirm the binding is healthy before a big charge.
$status = $accountLinking->inquiry(new InquiryRequest(
    accountToken:       $bound->accountToken,
    partnerReferenceNo: 'INQ-' . bin2hex(random_bytes(4)),
));
$status->isActive();  // true → safe to transact

// 4. When the user disconnects their wallet: revoke the accountToken.
$accountLinking->unbind(new UnbindRequest(
    accountToken:       $bound->accountToken,
    partnerReferenceNo: 'UNBIND-' . bin2hex(random_bytes(4)),
));
// After unbind, the accountToken can no longer be used for new charges.
```

`authCode` expires **30 minutes** after issuance — a delayed exchange
surfaces as `ApiException` with a `4030700`-class response code.

Full walkthrough: [`examples/01-account-linking.php`](examples/01-account-linking.php).

### Link & Pay

One-shot debit charge. The user must confirm inside the ShopeePay app
(or browser) before settlement; you redirect them to `webRedirectUrl` and
either poll `checkStatus()` or wait for the notify webhook.

```php
use ShopeePay\Dto\Common\Money;
use ShopeePay\Dto\LinkAndPay\{CheckStatusRequest, CreatePaymentRequest, RefundRequest};

$payment = $linkAndPay->create(new CreatePaymentRequest(
    partnerReferenceNo: 'ORDER-' . bin2hex(random_bytes(4)),
    amount:             new Money('150000.00'),  // 150,000 IDR
    accountToken:       $bound->accountToken,
));
// header("Location: {$payment->webRedirectUrl}");

// Poll (the SDK does NOT auto-poll — that's caller territory).
$status = $linkAndPay->checkStatus(new CheckStatusRequest(
    originalReferenceNo: $payment->referenceNo,
));
if ($status->isTerminal() && $status->isSuccess()) {
    // Mark order paid.
}

// Refund — same partner-ref pattern.
$linkAndPay->refund(new RefundRequest(
    refundAmount:        new Money('150000.00'),
    partnerRefundNo:     'REFUND-' . bin2hex(random_bytes(4)),
    originalReferenceNo: $payment->referenceNo,
    reason:              'duplicate charge',
));
```

Suggested polling cadence: **5 seconds × 20 attempts, then 5 minutes × 6**.
The SDK intentionally doesn't bake this in — many integrations stop polling
once the notify webhook (svc 56) arrives.

Full walkthrough: [`examples/02-link-and-pay.php`](examples/02-link-and-pay.php).

### Subscription

Recurring debit. Shares the create endpoint path with Link & Pay; the
gateway disambiguates via the required `subscriptionId` field (which your
billing system stores at signup-time, an out-of-band step v1 doesn't cover).

```php
use ShopeePay\Dto\Subscription\{CheckStatusRequest, CreatePaymentRequest, RefundRequest};

$charge = $subscription->create(new CreatePaymentRequest(
    partnerReferenceNo: 'CHARGE-' . bin2hex(random_bytes(4)),
    amount:             new Money('99000.00'),
    accountToken:       $bound->accountToken,
    subscriptionId:     'SUB-2026-7',   // your billing system's id for this binding
));
```

Completion notifies arrive on **svc 52**, not svc 56 — the
[webhook router](#webhook-handling) routes them to
`SubscriptionPaymentCompleted` / `SubscriptionPaymentFailed`.

Full walkthrough: [`examples/03-subscription.php`](examples/03-subscription.php).

### Auth & Capture

Card-on-file: hold funds, settle later, optionally release un-captured
balance. Four operations + three queries:

```php
use ShopeePay\Dto\AuthCapture\{AuthorizeRequest, CaptureRequest, RefundRequest, VoidRequest};

$auth = $authCapture->authorize(new AuthorizeRequest(
    partnerReferenceNo: 'AUTH-' . bin2hex(random_bytes(4)),
    amount:             new Money('250000.00'),       // max settleable
    accountToken:       $bound->accountToken,
    validUpTo:          '2026-06-08T10:00:00.000+07:00',
));

$capture = $authCapture->capture(new CaptureRequest(
    captureAmount:       new Money('180000.00'),       // partial — 70k released
    partnerReferenceNo:  'CAP-' . bin2hex(random_bytes(4)),
    originalReferenceNo: $auth->referenceNo,
));

// To reverse: refund a captured charge.
$authCapture->refund(new RefundRequest(
    refundAmount:        new Money('180000.00'),
    partnerRefundNo:     'REFUND-' . bin2hex(random_bytes(4)),
    originalReferenceNo: $capture->referenceNo,
));

// To cancel BEFORE capture: void the authorization.
$authCapture->void(new VoidRequest(
    partnerReferenceNo:  'VOID-' . bin2hex(random_bytes(4)),
    originalReferenceNo: $auth->referenceNo,
));
```

State-machine rules (gateway-enforced — the SDK does NOT pre-validate):

- Capture must occur before the auth's `validUpTo` expires (24h default, 14d max).
- **One partial capture per authorization.** Unreserved balance is released to the customer.
- Void must occur before capture.
- Refund must occur after a successful capture.

Violations surface as `ApiException` with the responseCode and message verbatim.

Full walkthrough: [`examples/04-auth-capture.php`](examples/04-auth-capture.php).

## Webhook handling

The verifier takes the **raw body string** — read it before any framework
middleware consumes the PSR-7 stream. The body-hash inside the
signature base is computed from the exact bytes ShopeePay sent; even a
re-serialized identical payload will fail verification.

```php
use ShopeePay\Webhook\Event\{
    AuthCaptured, PaymentCompleted, PaymentFailed, RefundCompleted,
    SubscriptionPaymentCompleted, SubscriptionPaymentFailed, UnknownEvent,
};

$event = $webhooks->parse(
    method:          $request->getMethod(),
    callbackPath:    $request->getUri()->getPath(),   // path only — no scheme/host
    rawBody:         (string) $request->getBody(),
    base64Signature: $request->getHeaderLine('X-SIGNATURE'),
    timestamp:       $request->getHeaderLine('X-TIMESTAMP'),
);

match (true) {
    $event instanceof PaymentCompleted             => $orderSvc->markPaid($event),
    $event instanceof PaymentFailed                => $orderSvc->markFailed($event),
    $event instanceof SubscriptionPaymentCompleted => $billingSvc->markPaid($event),
    $event instanceof SubscriptionPaymentFailed    => $billingSvc->markFailed($event),
    $event instanceof AuthCaptured                 => $orderSvc->markSettled($event),
    $event instanceof RefundCompleted              => $orderSvc->markRefunded($event),
    $event instanceof UnknownEvent                 => $logger->warning(
        'unknown shopeepay event',
        ['svc' => $event->serviceCode, 'status' => $event->latestTransactionStatus],
    ),
};
```

The `UnknownEvent` arm is **mandatory**. `EventFactory` returns it for any
service code the SDK doesn't recognize — that's how the library stays
forward-compatible with new ShopeePay notifications. Without the arm, new
event types silently drop on the floor.

Errors the verifier raises:

- `SignatureException` — signature mismatch, timestamp outside the 5-min
  replay window, or `openssl_verify` returned `-1`. Respond `401`; ShopeePay
  will retry.
- `WebhookException` — malformed body, non-POST method. Respond `400`;
  retries won't help.

Full walkthrough: [`examples/05-webhook-handler.php`](examples/05-webhook-handler.php).

## Errors

The SDK throws a small hierarchy, all under `ShopeePay\Exception\`:

| Exception            | Raised when                                                  |
| -------------------- | ------------------------------------------------------------ |
| `ConfigException`    | Bad PEM, empty creds, out-of-range TTLs (at `new Config()`)  |
| `NetworkException`   | PSR-18 client error, unparseable response body               |
| `SignatureException` | Webhook signature mismatch / replay window / openssl error   |
| `WebhookException`   | Malformed webhook body, non-POST method                      |
| `ApiException`       | Gateway returned a non-`200xxxx` response code               |
| `ShopeePayException` | Base class — catch this to swallow everything                |

Caller-side `InvalidArgumentException` fires from DTO constructors on
missing or empty required fields (per locked decision #5).

## Configuration

`Config` constructor parameters (all named):

| Field                          | Required | Default        | Notes                                          |
| ------------------------------ | -------- | -------------- | ---------------------------------------------- |
| `clientId`                     | ✅       |                |                                                |
| `clientSecret`                 | ✅       |                | HMAC-SHA512 key for transaction signing        |
| `privateKey`                   | ✅       |                | PEM string for RSA access-token signing        |
| `shopeepayPublicKey`           | ✅       |                | PEM string for webhook verification            |
| `merchantId`                   | ✅       |                |                                                |
| `httpClient`                   | ✅       |                | PSR-18                                         |
| `requestFactory`               | ✅       |                | PSR-17 RequestFactoryInterface                 |
| `streamFactory`                | ✅       |                | PSR-17 StreamFactoryInterface                  |
| `cache`                        | ✅       |                | PSR-16; access-token cache                     |
| `environment`                  |          | `SANDBOX`      | `Environment::SANDBOX` or `::PRODUCTION`       |
| `storeId`                      |          | `null`         | Multi-outlet merchants only                    |
| `channelId`                    |          | `'95221'`      | SNAP BI e-money default                        |
| `tokenTtlSeconds`              |          | `840` (14min)  | Tentative — sandbox probe confirms             |
| `webhookReplayWindowSeconds`   |          | `300` (5min)   | Tight enough to deny replay, loose for drift   |
| `logger`                       |          | `null`         | PSR-3; `LogScrubber` redacts secrets first     |

## Examples

Five runnable scripts plus a shared bootstrap:

| Script                                                                 | What it shows                                                |
| ---------------------------------------------------------------------- | ------------------------------------------------------------ |
| [`examples/_bootstrap.php`](examples/_bootstrap.php)                   | Wiring the SDK from env vars                                 |
| [`examples/01-account-linking.php`](examples/01-account-linking.php)   | Consent URL → bind → inquiry → unbind                        |
| [`examples/02-link-and-pay.php`](examples/02-link-and-pay.php)         | Create → poll (5s×20 then 5m×6) → refund                     |
| [`examples/03-subscription.php`](examples/03-subscription.php)         | Recurring charge + subscriptionId disambiguation             |
| [`examples/04-auth-capture.php`](examples/04-auth-capture.php)         | Authorize → query → capture → refund (and the void branch)   |
| [`examples/05-webhook-handler.php`](examples/05-webhook-handler.php)   | Raw-body buffering, signature errors, `UnknownEvent` arm     |

To run: set the `SHOPEEPAY_*` env vars (see [`.env.example`](.env.example)).
PEMs can be passed inline via `SHOPEEPAY_PRIVATE_KEY` / `SHOPEEPAY_PUBLIC_KEY`,
or as file paths via `SHOPEEPAY_PRIVATE_KEY_PATH` / `SHOPEEPAY_PUBLIC_KEY_PATH`
(shell-friendly fallback). Then: `php examples/02-link-and-pay.php`.

## Development

All build/test commands run inside a dedicated PHP container so they do not
collide with whatever PHP setup you have on the host.

```bash
make install                  # composer install
make test                     # phpunit Unit suite
make phpstan                  # static analysis level 8
make test-integration         # gated on SHOPEEPAY_* env
make shell                    # bash inside the container

# Test against any PHP version in the CI matrix:
make test PHP_VERSION=8.3

# Run the full matrix locally:
make matrix
```

CI runs on PHP 8.1, 8.2, 8.3, 8.4 against dependency `lowest` and `highest`.

If you prefer native PHP (no docker), `composer install && vendor/bin/phpunit`
also works as long as your local PHP has `openssl`, `mbstring`, `dom`, `zip`,
and `curl` enabled.

### Sandbox probe

Several SDK constants are documented as tentative pending empirical
confirmation: the access-token TTL (assumed 14 minutes), the six
`/v1.0/auth/*` endpoint paths (only `/v1.0/auth/refund` is design-doc
pinned), and the `channelId` value (`95221`, SNAP BI e-money default).

[`scripts/probe-sandbox.php`](scripts/probe-sandbox.php) issues a single
access-token request (prints the gateway's `expiresIn`) and then walks the
real onboarding flow end-to-end, each step chaining into the next:

1. **get-auth-code** — signed server-to-server GET; the gateway returns the
   `authCode` directly in its JSON body.
2. **registration-account-binding** — exchanges the `authCode` for an
   `accountToken`.
3. **debit/payment-host-to-host** — charges using the `accountToken`.
4. **debit/status** — queries the debit just attempted.
5. **registration-account-unbinding** — revokes the `accountToken` (cleanup).

Each step prints its full raw response and is classified `success`,
`looks-valid-path`, `may-be-wrong-path`, or `indeterminate-*`. Without a real
`SHOPEEPAY_AUTH_CODE` / `SHOPEEPAY_ACCOUNT_TOKEN`, the later steps send
deliberately-invalid values so the path is still validated before any state
mutation; with real values the probe **does** exercise real state (use sandbox
creds).

```bash
export SHOPEEPAY_CLIENT_ID=...
export SHOPEEPAY_SECRET_KEY=...
export SHOPEEPAY_SUBS_MERCHANT_ID=...
export SHOPEEPAY_SUBS_STORE_ID=...          # externalStoreId (debit, status)
export SHOPEEPAY_PRIVATE_KEY_PATH=./.keys/merchant-private.pem
export SHOPEEPAY_PUBLIC_KEY_PATH=./.keys/shopeepay-public.pem
# (or pass PEMs inline via SHOPEEPAY_PRIVATE_KEY / SHOPEEPAY_PUBLIC_KEY)

# Optional, to drive specific steps with real values:
export SHOPEEPAY_AUTH_CODE=...        # real code from a consent redirect → bind
export SHOPEEPAY_ACCOUNT_TOKEN=...    # accountToken → debit, unbind
export SHOPEEPAY_ORIGINAL_REF=...     # partnerReferenceNo to query → debit/status
                                      # (needed for a standalone --only=status)

make probe                               # full flow, human-readable report
make probe ARGS=--only=bind              # run ONE step in isolation
make probe ARGS=--json                   # JSON report
make probe ARGS=--production             # against live API (confirms first)
```

`--only=<step>` accepts `auth-code | bind | debit | status | unbind | token`
(the access-token probe always runs since every step needs a token). Use it to
iterate on a single endpoint without re-running the whole chain.

Place your PEM keys somewhere inside the project tree (e.g. `./.keys/`,
gitignored) so the dev container can reach them via its `/app` bind mount.
After running, update `src/Service/AuthCaptureService.php` paths and
`Config::$tokenTtlSeconds` if anything classified as `may-be-wrong-path`
or if `expiresIn` diverged from 840.

## License

MIT — see [LICENSE](LICENSE).

## References

- ShopeePay docs — Account Linking: https://product.shopeepay.co.id/integration/api/account-linking/php/
- ShopeePay docs — Subscription (debit/payment-host-to-host request shape): https://product.shopeepay.co.id/integration/api/subscription/
- Base URLs: `api.snap.airpay.co.id` (prod), `api.snap.uat.airpay.co.id` (sandbox)
