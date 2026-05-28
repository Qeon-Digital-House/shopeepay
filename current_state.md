# Current State — ShopeePay PHP SDK

**Snapshot:** 2026-05-28 (post-README). Handoff doc for a fresh Claude session.

## What this project is

A Composer-installable PHP SDK for ShopeePay (SNAP BI). Hackathon/demo mode.
PHP 8.1+. Framework-agnostic. Four flows in v1: Account Linking, Subscription,
Link&Pay, Auth&Capture.

Reference docs: `https://product.shopeepay.co.id/integration/api/account-linking/php/`
Base URLs: `api.snap.airpay.co.id` (prod), `api.snap.uat.airpay.co.id` (sandbox).

## Where we are

- **Design phase complete.** Plan reviewed via `/office-hours` + `/plan-eng-review`.
- **Spec review passed:** 2 iterations, final score 8.5/10.
- **Build-order steps 1–10 done** (scaffold, kernel, transport+token,
  webhook, AccountLinking, LinkAndPay, Subscription, AuthCapture,
  Examples, README). Branch `scaffold-shopeepay-sdk`. 136 unit tests pass,
  phpstan level 8 clean, all examples parse clean.
- **Next:** build-order step 11 — sandbox probe to confirm the 6 guessed
  `/v1.0/auth/*` paths, the access-token TTL (currently 14 min default),
  and refund time windows. Then 0.1.0 release (step 12).

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

## Build order

1. ✅ **Scaffold** — `git init`, composer (PSR-4 `ShopeePay\`), phpunit,
   phpstan level 8, GH Actions matrix (8.1-8.4 × lowest/highest), fixture key pair.
2. ✅ **Kernel** — Signer (+3 known-vector tests), BodyMinifier, HeaderBuilder,
   StatusCode, ErrorMapper, LogScrubber, Environment enum, exception hierarchy,
   Money DTO, Config.
3. ✅ **Transport + AccessTokenManager** — signed PSR-18 send, retry-once on
   401-or-4011xxx, PSR-16 cache.
4. ✅ **Webhook subsystem** — Verifier (parses key in ctor, 5-min replay window)
   + EventFactory (svc-code + refund-shape dispatch) + 7 typed events.
5. ✅ **AccountLinkingService** — buildAuthCodeUrl (URL builder, no HTTP) +
   bind/unbind/inquiry POSTs routed through Transport. 7 DTOs with ctor
   validation; `GetAuthCodeRequest::generateState()` helper for CSRF token.
   Endpoint paths are SNAP-BI-convention guesses, pending sandbox confirmation.
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
11. ⏭ **Sandbox probe** — START HERE. Empirically confirm:
    - the 6 SNAP-BI-convention `/v1.0/auth/*` paths (only
      `/v1.0/auth/refund` is design-doc-pinned),
    - access-token TTL (assumed 14 min — `Config::$tokenTtlSeconds`),
    - debit refund window (svc 58),
    - that `channelId='95221'` is accepted across all four flows.
    Update the corresponding consts in `Config` and the service paths
    if the probe surfaces mismatches.
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

- WSL2 Linux. Host `php` at `/usr/local/bin/php` is **sandboxed** — it can't
  read `/tmp` or even the project's own `phpunit.xml.dist`, and `phpunit`
  picks up a stray config at `/var/www/html/rrq-topup/phpunit.xml` if you
  invoke it directly. **Always use `make test` / `make phpstan`** — those
  run in the project's Docker container (`shopeepay-dev:8.1`) and work
  correctly. PHP inside the container is 8.1.34.
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
Resume at build-order step 11 (sandbox probe).
```
