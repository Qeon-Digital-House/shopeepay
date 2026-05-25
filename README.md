# shopeepay-php

Unofficial PHP SDK for **ShopeePay** (SNAP BI). Framework-agnostic, typed DTOs,
PSR-18/17/16/3 throughout. PHP 8.1+.

Status: **pre-alpha**, under active scaffolding. Not yet on Packagist.

## Why this exists

There is no official PHP SDK from ShopeePay. Every merchant ends up
re-implementing asymmetric RSA-SHA256 signing for access tokens, symmetric
HMAC-SHA512 signing for transactions (with the body-hash inside the
stringToSign), webhook signature verification, response-code parsing, and the
processing-state polling pattern. Most get at least one of these wrong.

This SDK fills that gap with the four flows merchants actually need.

## Flows in v1

- **Account Linking** — Get Auth Code → Bind → Inquiry → Unbind.
- **Link & Pay** — Create payment → Check status → Refund.
- **Subscription** — Create → Check status → Refund.
- **Auth & Capture** — Authorize → Capture → Void → Refund → Queries.
- **Webhook verifier** — RSA-SHA256 + replay protection, returns typed events.

## Install (once published)

```bash
composer require rrq/shopeepay-php
```

## Quickstart

```php
use ShopeePay\ShopeePay;
use ShopeePay\Config;
use ShopeePay\Environment;

$shopeepay = new ShopeePay(new Config(
    clientId:           getenv('SHOPEEPAY_CLIENT_ID'),
    clientSecret:       getenv('SHOPEEPAY_SECRET_KEY'),
    privateKey:         getenv('SHOPEEPAY_PRIVATE_KEY'),     // PEM string
    shopeepayPublicKey: getenv('SHOPEEPAY_PUBLIC_KEY'),      // PEM string
    merchantId:         getenv('SHOPEEPAY_CWS_MERCHANT_ID'),
    storeId:            getenv('SHOPEEPAY_CWS_STORE_ID') ?: null,
    environment:        filter_var(getenv('SHOPEEPAY_IS_PRODUCTION'), FILTER_VALIDATE_BOOL)
                            ? Environment::PRODUCTION
                            : Environment::SANDBOX,
    httpClient:         $psr18Client,
    requestFactory:     $psr17RequestFactory,
    streamFactory:      $psr17StreamFactory,
    cache:              $psr16Cache,
    logger:             $psr3Logger,
));

$payment = $shopeepay->linkAndPay()->create(/* CreatePaymentRequest */);
```

See [`.env.example`](.env.example) for the full set of expected environment
variables. The SDK itself reads no `$_ENV` directly — it takes everything via
`Config` — but the example uses the env-var names that match real merchant
deployments.

Full per-flow guides land alongside their service implementations.

## Webhook handling

The verifier takes the **raw body string** — read it before any framework
middleware consumes the PSR-7 stream.

```php
$event = $shopeepay->webhooks()->parse(
    method:        $request->getMethod(),
    callbackPath:  $request->getUri()->getPath(),
    rawBody:       (string) $request->getBody(),
    signature:     $request->getHeaderLine('X-SIGNATURE'),
    timestamp:     $request->getHeaderLine('X-TIMESTAMP'),
);

match (true) {
    $event instanceof PaymentCompleted             => $orderSvc->markPaid($event),
    $event instanceof PaymentFailed                => $orderSvc->markFailed($event),
    $event instanceof SubscriptionPaymentCompleted => $billingSvc->markPaid($event),
    default                                        => $logger->warning('unknown shopeepay event', ['svc' => $event->serviceCode]),
};
```

The `default` arm is **mandatory** — `EventFactory` returns `UnknownEvent` for
forward-compat against new ShopeePay service codes. Without a `default` you
silently drop events.

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

## License

MIT — see [LICENSE](LICENSE).

## References

- ShopeePay docs: https://product.shopeepay.co.id/integration/api/account-linking/php/
- Base URLs: `api.snap.airpay.co.id` (prod), `api.snap.uat.airpay.co.id` (sandbox)
