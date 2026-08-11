# Release Notes

## [Unreleased](https://github.com/gts-meghni/laravel-satim/compare/v1.0.0...HEAD)

## [v1.0.0](https://github.com/gts-meghni/laravel-satim/releases/tag/v1.0.0) - 2026-08-11

### Added

- `Satim` facade covering register, order status, acknowledge, refund, and receipt calls against the SATIM gateway.
- Typed results (`RegisterResult`, `SatimOrderState`, `AcknowledgeResult`, `RefundResult`, `Receipt`) and enums (`SatimLanguage`, `SatimOperation`, `SatimOrderStatus`).
- Audit trail of every gateway call through the `satim_calls` table, driven by the `SatimCallStarted`, `SatimCallCompleted`, and `SatimCallFailed` events.
- `satim:check` Artisan command for verifying credentials and connectivity.
- Publishable config, translations (Arabic, English, French), assets, and migration under the `satim`, `satim-config`, `satim-lang`, `satim-assets`, and `satim-migrations` tags.
