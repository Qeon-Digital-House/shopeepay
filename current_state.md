# Current State — ShopeePay PHP SDK

**Snapshot:** 2026-06-03 (probe running against live sandbox). Handoff doc for a fresh Claude session.

## What this project is

A Composer-installable PHP SDK for ShopeePay (SNAP BI). Hackathon/demo mode.
PHP 8.1+. Framework-agnostic. Four flows in v1: Account Linking, Subscription,
Link&Pay, Auth&Capture.

Reference docs: `https://product.shopeepay.co.id/integration/api/account-linking/php/`
Base URLs: `api.snap.airpay.co.id` (prod), `api.snap.uat.airpay.co.id` (sandbox).

## Where we are

- **Design phase complete.** Plan reviewed via `/office-hours` + `/plan-eng-review`.
- **Spec review passed:** 2 iterations, final score 8.5/10.
- **Build-order steps 1–10 done; step 11 probe RUNNING against live sandbox.**
  Probe script (`scripts/probe-sandbox.php`) + `make probe` rewritten as an
  end-to-end account-linking → debit flow probe and is now being driven
  against the UAT gateway with real creds. 136 unit tests pass, phpstan
  level 8 clean.
- **Empirical findings so far (from live probe runs) — see "Verified request
  shapes" below.** The gateway's field-level `4000702 Invalid Mandatory
  Field {…}` errors revealed the real request shapes for two endpoints, and
  they differ from what the SDK DTOs currently emit:
  - `/v1.0/registration-account-binding`: needs **top-level `merchantId`**;
    `authCode` and `partnerReferenceNo` are **mutually exclusive** (send
    exactly one). Path has **no `/bind` suffix**.
  - `/v1.1/debit/payment-host-to-host`: needs top-level `merchantId`,
    `externalStoreId`, `amount`, and a mandatory **`urlParams[]`** array
    (`url` + `type=PAY_RETURN` + `isDeepLink`); **`accountToken` goes inside
    `additionalInfo`**, not top-level.
  - get-auth-code returns `authCode` **directly in its JSON body**
    (`responseCode 2001000`); the probe chains it into bind.
- **Next:**
  1. Finish reconciling probe output — capture the gateway `expiresIn`
     (update `Config::$tokenTtlSeconds` if ≠ 840s) and reclassify the 6
     `/v1.0/auth/*` paths in `AuthCaptureService.php`.
  2. **Fix the SDK DTOs to match the verified shapes** (probe-only so far):
     `BindAccountRequest` (+ `AccountLinkingService::PATH_BIND` → drop
     `/bind`, add `merchantId`, one-of authCode/partnerReferenceNo);
     `UnbindRequest` (+ `PATH_UNBIND` → drop `/unbind`, add top-level
     `merchantId`, move the token into `additionalInfo.accountToken` —
     currently emits a top-level `tokenId`); the LinkAndPay/Subscription
     `CreatePaymentRequest` (add `merchantId`, `externalStoreId`,
     `urlParams`; move `accountToken` into `additionalInfo`); and the
     LinkAndPay/Subscription `CheckStatusRequest` (add `merchantId`,
     `externalStoreId`, `serviceCode`, and an `amount` object). Update the
     corresponding unit tests.
  3. Then 0.1.0 release (step 12).

## Source-of-truth files

| File | Role |
|---|---|
| `~/.gstack/projects/shopeepay/qdh-init-design-20260525-151513.md` | **APPROVED design doc** — read this first |
| `~/.gstack/projects/shopeepay/qdh-init-eng-review-test-plan-20260525-151513.md` | Test plan (111 unit + 6 E2E, file map) |
| `/home/qdh/RRQ/shopeepay/TODOS.md` | One v2 item: `shopeepay-laravel` companion package |

## Architectural shape (chosen: Approach B)

Service-per-flow with typed DTOs. Stripe-PHP shape. ~30 files.

```
src/
  ShopeePay.php (facade)
  Config.php, Environment.php (enum)
  Http/{Signer, Transport, AccessTokenManager, RequestFactory,
        HeaderBuilder, BodyMinifier, StatusCode, ErrorMapper, LogScrubber}
  Service/{AccountLinking, LinkAndPay, Subscription, AuthCapture}Service.php
  Dto/{Common, AccountLinking, LinkAndPay, Subscription, AuthCapture}/...
  Webhook/{Verifier, EventFactory, Event/*}
  Exception/{ShopeePay, Network, Signature, Api, Config, Webhook}Exception.php
tests/{Unit, Integration, fixtures}
examples/01-05-*.php
```

## Locked decisions (7, from eng review)

1. `Config` takes `privateKey`/`shopeepayPublicKey` as **PEM strings**, not file paths.
2. Token invalidation triggers on **HTTP 401 OR `responseCode` matching `4011xxx`** in body.
   Retry once; second failure → `ApiException` (no loop).
3. `Http\LogScrubber` redacts `accessToken`, `X-SIGNATURE`, `mobileNumber`,
   `accountToken`, PEM strings before PSR-3 logger sees them.
4. **Three separate Refund DTOs** (LinkAndPay, Subscription, AuthCapture) — no DRY merge.
5. DTO constructors validate inputs and throw `\InvalidArgumentException`.
   `Money` enforces numeric, 2 decimals, IDR-only.
6. E2E sandbox tests run on every push, **gated on `SHOPEEPAY_*` GitHub Actions secrets**.
7. `Webhook\Verifier` parses public key **once in constructor**, holds the `OpenSSLAsymmetricKey`.

## Critical PHP-8.1 pitfalls (do not regress)

- **No `readonly class`** (8.2+). Use per-property `public readonly`.
- **No `Random\Randomizer`** (8.2+). Use `random_bytes()` + `bin2hex()`.
- `match` with type checks must use `match (true) { $x instanceof Foo => ... }`.
- `json_encode` flags: `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
- All timestamps in **Asia/Jakarta** (`+07:00`). Access-token format
  `Y-m-d\TH:i:sP`; transaction format `Y-m-d\TH:i:s.vP` (millis required).
- `CHANNEL-ID` header literal value: `95221`.
- `StatusCode` parse: 7 digits = HTTP(3) + Service(2) + Sub(2).
- Webhook `Verifier::parse()` takes a **raw string body**, not a PSR-7 stream.
- `openssl_verify` returns -1/0/1 — handle all three explicitly.

## Signing (the part everyone gets wrong)

**Access Token (RSA-SHA256):** `stringToSign = "{clientKey}|{timestamp}"`, sign w/ merchant private key.

**Transaction (HMAC-SHA512):**
`stringToSign = "{METHOD}:{path}:{accessToken}:{Hex(SHA256(minifiedBody))}:{timestamp}"`,
sign w/ client secret.

**Webhook verify (RSA-SHA256):**
`stringToSign = "POST:{callbackPath}:{Hex(SHA256(rawBody))}:{timestamp}"`,
verify w/ ShopeePay public key. Path-only, no scheme/host.

Three known-vector tests required in `tests/Unit/Http/SignerTest.php` —
fixtures in `tests/fixtures/` (test-only PEM key pair).

## Verified request shapes (from live sandbox probe, 2026-06-03)

Confirmed empirically by the probe against the UAT gateway. These are the
**ground-truth** bodies; the SDK DTOs do not all match yet (see "Next").
Source: `https://product.shopeepay.co.id/integration/api/subscription/` and
`.../account-linking/php/`, cross-checked against gateway `4000702` errors.

**get-auth-code** (`GET /v1.0/get-auth-code`, svc 10) — signed server-to-server
GET, NOT a plain browser redirect. Returns the `authCode` directly in the JSON
body:
```json
{ "responseCode": "2001000", "responseMessage": "Successful",
  "authCode": "…", "state": "…" }
```

**registration-account-binding** (`POST /v1.0/registration-account-binding`,
svc 07) — note: **no `/bind` suffix**. `merchantId` is top-level mandatory;
`authCode`/`partnerReferenceNo` are **mutually exclusive** (exactly one):
```json
{ "merchantId": "Merchant123", "authCode": "ATXGbzzNg5daW" }
```

**debit/payment-host-to-host** (`POST /v1.1/debit/payment-host-to-host`,
svc 54) — `urlParams[]` is mandatory; `accountToken` lives inside
`additionalInfo`:
```json
{
  "partnerReferenceNo": "…",
  "merchantId": "Merchant123",
  "externalStoreId": "Store123",
  "amount": { "value": "10000.00", "currency": "IDR" },
  "urlParams": [ { "url": "https://…", "type": "PAY_RETURN", "isDeepLink": "N" } ],
  "additionalInfo": { "accountToken": "…" }
}
```

**debit/status** (`POST /v1.0/debit/status`, svc 55) — query a debit's status.
Mandatory: `originalPartnerReferenceNo` (the partner ref of the txn to query),
`merchantId`, `externalStoreId`, `serviceCode` (the queried txn's service —
`54` for debit), and an **`amount`** object. Omitting `amount.value` trips
`4005502 Invalid mandatory field {value}`:
```json
{
  "originalPartnerReferenceNo": "…",
  "merchantId": "Merchant123",
  "externalStoreId": "Store123",
  "serviceCode": "54",
  "amount": { "value": "10000.00", "currency": "IDR" }
}
```

**registration-account-unbinding** (`POST /v1.0/registration-account-unbinding`,
svc 09) — **no `/unbind` suffix**. `merchantId` top-level mandatory; the binding
to revoke is identified by `additionalInfo.accountToken` OR top-level
`partnerReferenceNo` (at least one — both may be sent together, unlike bind):
```json
{
  "merchantId": "Merchant123",
  "partnerReferenceNo": "…",
  "additionalInfo": { "accountToken": "…" }
}
```

Recurring theme: **every POST endpoint needs top-level `merchantId`**, and most
also need `externalStoreId` — the SDK DTOs do not yet supply these.

Probe env knobs: `SHOPEEPAY_AUTH_CODE` (real code from a consent redirect —
required to actually bind when running `--only=bind`), `SHOPEEPAY_ACCOUNT_TOKEN`
(for debit/unbind), `SHOPEEPAY_ORIGINAL_REF` (the partnerReferenceNo to query
for `--only=status` — without it the status step queries a never-debited random
ref). Run a single step with
`make probe ARGS=--only=<auth-code|bind|debit|status|unbind|token>`.

## Build order

1. ✅ **Scaffold** — `git init`, composer (PSR-4 `ShopeePay\`), phpunit,
   phpstan level 8, GH Actions matrix (8.1-8.4 × lowest/highest), fixture key pair.
2. ✅ **Kernel** — Signer (+3 known-vector tests), BodyMinifier, HeaderBuilder,
   StatusCode, ErrorMapper, LogScrubber, Environment enum, exception hierarchy,
   Money DTO, Config.
3. ✅ **Transport + AccessTokenManager** — signed PSR-18 send, retry-once on
   401-or-4011xxx, PSR-16 cache. `Transport::get()` adds signed-GET support
   (path-with-query signing via the documented rawurlencode(decoded-query)
   quirk), sharing the retry loop with `send()`.
4. ✅ **Webhook subsystem** — Verifier (parses key in ctor, 5-min replay window)
   + EventFactory (svc-code + refund-shape dispatch) + 7 typed events.
5. ✅ **AccountLinkingService** — buildAuthCodeUrl (URL builder, no HTTP),
   getAuthCode (signed server-to-server GET → `GetAuthCodeResponse` with the
   authCode, confirmed by the probe), bind/unbind/inquiry POSTs routed through
   Transport. DTOs with ctor validation; `GetAuthCodeRequest::generateState()`
   helper for CSRF token. NOTE: bind/unbind request shapes + paths still need
   the probe-verified corrections (see "Next" item 2).
6. ✅ **LinkAndPayService** — create (svc 54, `/v1.1/debit/payment-host-to-host`),
   checkStatus (svc 55, `/v1.0/debit/status`), refund (svc 58, `/v1.0/debit/refund`).
   CheckStatusResponse exposes `isSuccess()` + `isTerminal()` so caller polling
   loops don't re-encode the SNAP BI status taxonomy. RefundResponse hydrates
   refundAmount as a Money (null on malformed gateway value, never throws).
7. ✅ **SubscriptionService** — same paths as LinkAndPay; gateway disambiguates
   on a required `subscriptionId` field. Notify lands on svc 52 (handled by
   existing `Webhook\EventFactory` dispatch). DTOs kept split from LinkAndPay
   per locked decision #4 — the refund DTO already diverges (subscriptionId).
8. ✅ **AuthCaptureService** + 14 DTOs — authorize/capture/void/refund +
   queryAuth/queryCapture/queryVoid (svc 63/65/67/69 + 64/66/68). Paths
   under `/v1.0/auth/*`; only `/v1.0/auth/refund` is pinned by the design
   doc, the rest are SNAP-BI-convention guesses (asserted in tests so the
   sandbox probe in step 11 surfaces mismatches loudly). State-machine
   constraints documented in DTO PHPDoc but NOT client-enforced — gateway
   is the source of truth and surfaces violations as `ApiException`.
9. ✅ **Examples** — 5 runnable scripts + a shared `_bootstrap.php` under
   `examples/`. Each script uses env vars for creds, short-circuits cleanly
   when they're absent, and demonstrates the polling cadence the SDK
   refuses to bake in (5s × 20 then 5m × 6). The webhook example carries
   the **`UnknownEvent` match arm** the design doc flagged as the critical
   docs gap. Bootstrap uses `Http\Discovery\Psr18ClientDiscovery::find()`
   so any PSR-18 client works (`composer require symfony/http-client`
   recommended). All 6 files lint clean.
10. ✅ **README** — install, quickstart with full PSR wiring (no facade),
    per-flow guide with one minimal snippet each, webhook section
    re-emphasizing the mandatory `UnknownEvent` match arm, errors table,
    `Config` field reference, and a table cross-linking the 5 example
    scripts. Existing references to a `ShopeePay` facade were stripped
    since none exists yet — the v2 Laravel companion package is where
    that ergonomic surface will live.
11. 🔶 **Sandbox probe — RUNNING against live UAT.** `scripts/probe-sandbox.php`
    was rewritten as an end-to-end flow probe: access-token (captures the
    gateway's `expiresIn`) → get-auth-code → registration-account-binding →
    debit/payment-host-to-host → debit/status → registration-account-unbinding,
    each step chaining into the next (authCode → accountToken → referenceNo;
    accountToken also drives the unbind cleanup). Each step dumps its full
    raw response (`printRawResponse`). Supports `--json`, `--production`
    (prompts), and **`--only=<step>`** to run a single endpoint in isolation
    (`make probe ARGS=--only=bind`; steps include `unbind`). `make probe` now forwards
    `SHOPEEPAY_AUTH_CODE` / `SHOPEEPAY_ACCOUNT_TOKEN` / `SHOPEEPAY_ORIGINAL_REF`
    into the container.
    **Findings landed in the probe** (see "Verified request shapes"): the
    real binding + debit body shapes, confirmed by chasing the gateway's
    `4000702 Invalid Mandatory Field {…}` errors field-by-field. The probe
    request bodies now match the gateway; the **SDK DTOs still need the same
    corrections** (see "Next" item 2).
    Still to finish: capture `expiresIn` and reclassify the 6 `/v1.0/auth/*`
    paths. Debit refund window remains probe-resistant (needs a real settled
    capture) — document in v0.1.0 release notes as a known unknown.
12. **Release 0.1.0** to Packagist via tag-triggered workflow.

## Known critical gap to mitigate during impl

`EventFactory` returns `UnknownEvent` for unrecognized service codes.
README example **must** include a `default => $logger->warning(...)` match arm
or callers silently drop unknown events. Documented in design doc's
"Failure Modes" table.

## Things explicitly OUT of scope (v1)

StatusPoller helper, non-IDR currency, `partnerReferenceNo` auto-gen,
Laravel/Symfony adapters (separate packages later), OpenAPI generated client,
Direct Debit, MPM (QR), CPM (QR), Checkout flows, refund time-window client
enforcement, concurrent token-refresh mutex.

## Environment notes

- WSL2 Linux. Host `php` at `/usr/local/bin/php` is **not a real PHP binary**
  — it is a one-line wrapper that runs `docker exec -i topup-rrq-topup-1 php`,
  i.e. it shells into a DIFFERENT project's container (rrq-topup) whose bind
  mount is `/var/www/html/rrq-topup`, so it cannot see this repo's files
  (`Could not open input file: scripts/probe-sandbox.php`). **Always use the
  `make` targets** — they run in this project's own dev container via
  `.docker/docker-compose.dev.yml` (`shopeepay-dev-81`). To lint/run ad hoc:
  `PHP_VERSION=8.1 DOCKER_UID=$(id -u) DOCKER_GID=$(id -g) docker compose -f
  .docker/docker-compose.dev.yml -p shopeepay-dev-81 run --rm --entrypoint ""
  php php -l scripts/probe-sandbox.php`. PHP inside the container is 8.1.x.
- The gstack helper scripts (`gstack-review-log`, `gstack-telemetry-log`)
  have CRLF line endings and fail with `bash\r: No such file or directory`.
  Not blocking. Review log was written manually to `~/.gstack/reviews/log.jsonl`.
- Git CRLF warnings appear on every `git add` (autocrlf is on). Files commit
  with LF endings — the warning is informational.
- EventFactory binds `originalPartnerReferenceNo`, **not** `partnerReferenceNo`
  — easy to miss when hand-rolling test payloads.

## Fresh-session pickup command

```
Read /home/qdh/RRQ/shopeepay/current_state.md, then
~/.gstack/projects/shopeepay/qdh-init-design-20260525-151513.md.
Finish reconciling the probe (capture expiresIn, reclassify /v1.0/auth/*),
then port the verified binding + debit request shapes from the probe into
the SDK DTOs ("Next" item 2), then resume at step 12 (release).
```
