# Laravel Satim

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep the Composer package name, namespaces, Composer metadata, documentation, and examples aligned with `gts-meghni/laravel-satim`.
- Name the runtime surface without the `laravel-` prefix: config file `config/satim.php` and key `satim`, routes file `routes/satim.php`, view and translation namespace `satim::`, publish tags `satim` and `satim-*`, published paths under `vendor/satim`, command signatures `satim:*`, table names `satim_*`, and public classes `Satim*`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Interpolate a plain variable without curly braces (`"order $orderNumber"`), and use braces only when the expression needs them, such as a method call or an array or property access (`"failed: {$e->getMessage()}"`). Pint accepts both forms, so unnecessary braces only add noise that editors flag.
- Document every `@throws` a method can raise, including the package exceptions raised by private helpers it calls. `phpstan.neon.dist` enforces this through `exceptions.check.missingCheckedExceptionInThrows`, and `tooWideThrowType` also rejects a documented exception the method cannot actually throw.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Never use em dashes (`—`) in `README.md` or any other documentation. Use a colon, semicolon, comma, or parentheses instead, and rephrase the sentence when none of those read well.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.
