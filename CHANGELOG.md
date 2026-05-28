# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial project scaffold: `composer.json`, PHPUnit 10, PHPStan level 8.
- GitHub Actions CI matrix: PHP 8.1–8.4 × dependency lowest/highest.
- Test-only RSA 2048 fixture key pairs under `tests/fixtures/`.
- Kernel: `Signer`, `BodyMinifier`, `HeaderBuilder`, `StatusCode`,
  `ErrorMapper`, `LogScrubber`, `Environment` enum, exception hierarchy,
  `Money` DTO, `Config`.
- `Transport` + `AccessTokenManager` — signed PSR-18 send loop with
  retry-once on 401 or `4011xxx`, PSR-16-cached access token.
- Webhook subsystem: `Verifier` (RSA-SHA256, 5-min replay window, public
  key parsed once at boot) + `EventFactory` + 7 typed events including
  `UnknownEvent` for forward compatibility.
- `AccountLinkingService` + 7 DTOs — get-auth-code URL builder plus
  bind / unbind / inquiry POSTs (svc 10/07/09/08).
- `LinkAndPayService` + 6 DTOs — create / checkStatus / refund
  (svc 54/55/58). `CheckStatusResponse::isSuccess()` and `isTerminal()`
  expose the SNAP BI status taxonomy.
- `SubscriptionService` + 6 DTOs — same shape as Link & Pay; gateway
  disambiguates on a required `subscriptionId` field. Notify completion
  lands on svc 52.
- `AuthCaptureService` + 14 DTOs — authorize / capture / void / refund
  plus queryAuth / queryCapture / queryVoid (svc 63/65/67/69 + 64/66/68).
  State-machine constraints (one partial capture per auth, void before
  capture, refund after capture) documented in DTO PHPDoc; gateway is
  the source of truth.
- 5 runnable example scripts under `examples/` covering all four flows
  plus a webhook receiver. Includes the mandatory `UnknownEvent` match arm.
- README quickstart + per-flow guide.
