---
status: completed
created: 2026-09-22
phase: 1/1
required_gates:
  test_verdict: false
  architect_review: false
  memory_update: true
---

# Issue #2: PHP 8.1 scoped runtime memory metrics polyfill

## Goal

Fix packaged scoped PHP 8.1 runtime startup when `open-telemetry/opentelemetry-metrics-runtime` memory metrics call `ini_parse_quantity()`.

## Confirmed failure

Docker PHP 8.1 with generated `scoped/81` package PHP code shows:

- `function_exists('ini_parse_quantity') = false`
- `function_exists('OTelDistroScoped\\ini_parse_quantity') = true`
- `MemoryMetrics::parseMemoryLimit('128M')` fatals with `Call to undefined function ini_parse_quantity()`.

PHP 8.2 with generated `scoped/82` package PHP code passes because native global `ini_parse_quantity()` exists.

## Phase 1

Scope:

- Apply the narrowest build/scoping fix so generated scoped PHP 8.1 code exposes a safe global `ini_parse_quantity()` shim when PHP does not provide the native function.
- Keep PHP 8.2+ behavior native and unchanged.
- Validate in Docker using generated artifacts for the matching PHP versions.

Acceptance criteria:

- Docker `php:8.1-cli-alpine` against generated `scoped/81` logs `function_exists(global ini_parse_quantity)=true` and parses `128M` as `134217728`.
- Docker `php:8.2-cli-alpine` against generated `scoped/82` logs native global availability and parses `128M` as `134217728`.
- Fix is idempotent and limited to build/scoping or focused validation support.
- Working tree diff contains only related changes.

Validation:

1. `./tools/build/build_php_code_for_packages.sh --php_versions '81' --skip_notice --skip_verify`
2. Docker PHP 8.1 repro against `_BUILT/php_code_for_packages/scoped/81/...`
3. `./tools/build/build_php_code_for_packages.sh --php_versions '82' --skip_notice --skip_verify`
4. Docker PHP 8.2 control against `_BUILT/php_code_for_packages/scoped/82/...`
5. Run the smallest relevant static/style check for touched files.

## Completion notes

- Implemented a no-`eval()` build/scoping fix in `tools/build/fix_scoped_composer_autoload.php`.
- The scoped Composer autoload fixer now generates `vendor/composer/otel_distro_php82_polyfill_aliases.php` and inserts it immediately after `symfony/polyfill-php82/bootstrap.php` in generated `autoload_files.php` and `autoload_static.php`.
- The generated alias file defines global `ini_parse_quantity()` only when PHP does not already provide it and the scoped Symfony PHP 8.2 polyfill function is available, delegating to `OTelDistroScoped\ini_parse_quantity()`.
- Docker PHP 8.1 with regenerated `scoped/81` logs global `ini_parse_quantity` availability and parses `128M` as `134217728`.
- Docker PHP 8.2 with regenerated `scoped/82` keeps using the native global function and parses `128M` as `134217728`.
- `php -l tools/build/fix_scoped_composer_autoload.php` passes.
