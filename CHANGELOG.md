# Release Notes

## [Unreleased](https://github.com/gts-meghni/laravel-satim/compare/v1.0.0...HEAD)

### Added

- `SatimErrorCode` enum covering every code in the SATIM integration guide, with `description()` per operation, reachable through `error()` on all three results.
- `docs/satim-gateway.md`, a reference for the gateway's endpoints, parameters, error codes, and order statuses.
- `Satim::orderNumber()`, which generates a 10 character order number and skips any this application already sent, according to the audit trail.
- Audit trail retention through `satim.audit.retention` (`SATIM_AUDIT_RETENTION`), applied by Laravel's `model:prune`. It defaults to ten years, following article 12 of the Algerian code de commerce, and nothing is deleted until the prune command is scheduled.
- `satim:reconcile` Artisan command, which confirms orders whose customer never returned from the payment page and fires `SatimOrderReconciled` with the gateway's answer.
- `satim.reconcile` config block: `after`, `within`, and `limit`.
- `Satim::fake()` for testing, which replaces the gateway transport while validation, events, and the audit trail keep running.
- `SatimFakeResponse` named responses (`registered`, `registerFailed`, `paid`, `declined`, `reversed`, `acknowledgeRefunded`, `refunded`, `refundFailed`, `connectionFailure`, `make`) and per-operation response queues.
- Assertions on the facade: `assertRegistered`, `assertAcknowledged`, `assertRefunded`, `assertSent`, `assertNotSent`, `assertNothingSent`, `assertSentCount`, and `recorded`.
- `SatimTransport` contract, bound to `SatimClient`, as the seam the fake replaces.

### Changed

- `SatimOperation` now carries its gateway endpoint, and the transport takes the operation instead of an endpoint string.

## [v1.0.0](https://github.com/gts-meghni/laravel-satim/releases/tag/v1.0.0) - 2026-08-11

### Added

- `Satim` facade covering register, order status, acknowledge, refund, and receipt calls against the SATIM gateway.
- Typed results (`RegisterResult`, `SatimOrderState`, `AcknowledgeResult`, `RefundResult`, `Receipt`) and enums (`SatimLanguage`, `SatimOperation`, `SatimOrderStatus`).
- Audit trail of every gateway call through the `satim_calls` table, driven by the `SatimCallStarted`, `SatimCallCompleted`, and `SatimCallFailed` events.
- `satim:check` Artisan command for verifying credentials and connectivity.
- Publishable config, translations (Arabic, English, French), assets, and migration under the `satim`, `satim-config`, `satim-lang`, `satim-assets`, and `satim-migrations` tags.
