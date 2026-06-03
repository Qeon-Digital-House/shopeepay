# CLAUDE.md — ShopeePay PHP SDK

Composer-installable PHP **8.1+** SDK for ShopeePay (SNAP-BI). Framework-agnostic,
Stripe-PHP-shaped (service-per-flow + typed DTOs). Four v1 flows: Account
Linking, Subscription, Link & Pay, Auth & Capture.

For the full handoff (build order, locked decisions, signing vectors, scope),
read **`current_state.md`** first. This file is the quick operational guide.

## Running anything (important)

**Do not call `php`/`phpunit`/`composer` directly on the host.** `/usr/local/bin/php`
is a wrapper that runs `docker exec -i topup-rrq-topup-1 php` — it shells into a
*different* project's container and cannot see this repo's files. Use the `make`
targets, which run in this project's own dev container
(`.docker/docker-compose.dev.yml`, project `shopeepay-dev-81`):

```bash
make test            # phpunit Unit suite
make phpstan         # static analysis, level 8
make test-integration
make matrix          # tests across PHP 8.1–8.4
make shell           # bash inside the container
```

Ad-hoc lint of a single file:
```bash
PHP_VERSION=8.1 DOCKER_UID=$(id -u) DOCKER_GID=$(id -g) docker compose \
  -f .docker/docker-compose.dev.yml -p shopeepay-dev-81 run --rm \
  --entrypoint "" php php -l scripts/probe-sandbox.php
```

## Sandbox probe

`scripts/probe-sandbox.php` (`make probe`) walks the live flow end-to-end:
access-token → get-auth-code → registration-account-binding →
debit/payment-host-to-host → debit/status, chaining each step's output into the
next. Run one step in isolation:

```bash
make probe ARGS=--only=bind     # steps: auth-code | bind | debit | status | token
make probe ARGS=--json
```

Probe env knobs (forwarded by the `make probe` target): `SHOPEEPAY_AUTH_CODE`,
`SHOPEEPAY_ACCOUNT_TOKEN`, `SHOPEEPAY_ORIGINAL_REF`, plus the standard
`SHOPEEPAY_*` creds. `--only=bind` skips get-auth-code, so a real authCode must
come from `SHOPEEPAY_AUTH_CODE`.

## Verified request shapes (confirmed against the live sandbox, 2026-06-03)

These are ground truth from the probe. **The SDK DTOs do not all match yet** —
when fixing the SDK, port these shapes and update the unit tests.

- **`GET /v1.0/get-auth-code`** (svc 10): signed server-to-server GET, not a
  plain browser redirect. Returns `authCode` directly in the JSON body
  (`responseCode 2001000`).
- **`POST /v1.0/registration-account-binding`** (svc 07): **no `/bind` suffix**.
  Top-level `merchantId` is mandatory; `authCode` and `partnerReferenceNo` are
  **mutually exclusive — send exactly one**. Sending both → `4000702 Invalid
  Mandatory Field {authCode} or {partnerReferenceNo}`.
- **`POST /v1.1/debit/payment-host-to-host`** (svc 54): top-level mandatory
  `merchantId`, `externalStoreId`, `amount`, and **`urlParams[]`** (each entry:
  `url` + `type=PAY_RETURN` + `isDeepLink` `Y`/`N`). **`accountToken` goes inside
  `additionalInfo`**, not top-level.

SDK files needing these corrections: `src/Dto/AccountLinking/BindAccountRequest.php`
+ `AccountLinkingService::PATH_BIND`; `src/Dto/{LinkAndPay,Subscription}/CreatePaymentRequest.php`.

## PHP 8.1 pitfalls (do not regress)

- No `readonly class` (8.2+) — use per-property `public readonly`.
- No `Random\Randomizer` (8.2+) — use `random_bytes()` + `bin2hex()`.
- `match (true) { $x instanceof Foo => ... }` for type checks.
- Request-body JSON must use `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`
  and no whitespace (the bytes are what gets HMAC-signed).
- Timestamps in Asia/Jakarta (`+07:00`). Access-token `Y-m-d\TH:i:sP`;
  transaction `Y-m-d\TH:i:s.vP` (millis required). `CHANNEL-ID` = `95221`.

## Signing (cheat sheet)

- **Access token (RSA-SHA256):** `{clientKey}|{timestamp}`, merchant private key.
- **Transaction (HMAC-SHA512):** `{METHOD}:{path}:{accessToken}:{Hex(SHA256(minifiedBody))}:{timestamp}`, client secret.
- **Webhook (RSA-SHA256):** `POST:{callbackPath}:{Hex(SHA256(rawBody))}:{timestamp}`, ShopeePay public key. Path-only.
- Signed GET quirk: the gateway URL-decodes the query then `rawurlencode`s the
  whole thing before signing — `4011000` means a stringToSign mismatch.

## Git / env notes

- `autocrlf` is on: `LF will be replaced by CRLF` warnings on `git add` are
  informational; files commit with LF.
- Commit messages end with the `Co-Authored-By: Claude` trailer.
