# TODOS

Tracked follow-up work, with enough context to pick up cold.

---

## Companion package: `shopeepay-laravel`

**What:** A separate Composer package that wires `shopeepay-php` into Laravel
via a ServiceProvider, Facade, and `config/shopeepay.php`.

**Why:** Laravel is the dominant PHP framework. The core SDK is intentionally
framework-agnostic (PSR-18/17/16/3), but Laravel users want one-line install:
`composer require shopeepay/shopeepay-laravel`, then `ShopeePay::linkAndPay()->create(...)`.

Keeping framework wiring out of the core package preserves citizenship for
Symfony/Slim/plain-PHP users. Splitting into a companion package is the
Stripe-PHP / Square-PHP precedent.

**Pros:**
- Lower friction for the largest PHP segment.
- Drives adoption of the core package.
- Reads `SHOPEEPAY_*` env vars idiomatically.
- Can publish artisan commands (e.g., `artisan shopeepay:probe-ttl`).

**Cons:**
- A second repo to maintain.
- Version-skew risk between core and companion (mitigated by semver caret).

**Context:**
- Core SDK lives in repo `shopeepay-php`.
- Companion lives in repo `shopeepay-laravel`, namespace `ShopeePay\Laravel\`.
- Provider binds singletons: `Config`, PSR-18 client (`Http\Client\Common\PluginClient`
  or Laravel's `Http` facade adapter), PSR-16 cache (`Illuminate\Cache\Repository`),
  PSR-3 logger (`Illuminate\Log\LogManager`).
- Facade exposes `ShopeePay::accountLinking()`, etc.
- Webhook route helper: `Route::shopeepayWebhook('/webhook', $handler)`.
- Test against Laravel 10, 11, 12 (current LTS + latest).

**Depends on / blocked by:**
- Core SDK v0.1.0 must ship and stabilize first (~2-4 weeks).
- Wait for one or two real Laravel-shaped tickets to land before publishing
  to confirm the API surface is right.

**Status:** Deferred until core SDK v0.1.0 ships.
