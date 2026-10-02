---
status: planned
created: 2026-09-30
task: 1/2
required_gates:
  exact_target_package_provenance: true
  bootstrap_environment_source_evidence: true
  declarative_resource_regression: true
  grpc_logger_provider_regression: true
  existing_laravel_e2e_validation: true
  architect_review: false
  memory_update: false
---

# Declarative configuration environment-resolution repair plan

**Goal:** Make the Distro resolve declarative `${VAR}` and `${VAR:-fallback}` values from the PHP process environment before it constructs scoped SDK providers, so a standard Laravel automatic log carries the configured E2E service and tenant resource values.

**Architecture:** The locked `open-telemetry/sdk-configuration` 0.9.0 parser accepts the supplied substitution syntax, but its default environment reader checks `$_SERVER` and PHP ini values rather than `getenv()`. Distro bootstrap loads scoped Composer files and can trigger SDK declarative configuration before application code observes its final superglobal state. Add a Distro bootstrap compatibility step before the scoped vendor autoloader: merge missing operating-system environment entries into `$_SERVER` without replacing entries already supplied by the SAPI. This makes the SDK reader see the same process environment as `getenv()` while preserving the SDK, OTLP/gRPC, and scoped-runtime ownership boundaries.

**Tech Stack:** PHP 8.1–8.5, OpenTelemetry SDK 1.15.0, SDK Configuration 0.9.0, PHP-Scoper, native PHP extension bootstrap, Docker DEB package tests, OTLP/gRPC, existing Laravel E2E fixture.

**Scope classification:** `multi-task`

## Invariants

- Do not change Laravel `LogWatcher`, inject resource values into Laravel log context, add application SDK calls, or alter gateway resource processors or tenant filtering.
- Preserve valid existing `$_SERVER` values; process-environment hydration may add only missing keys and must not overwrite SAPI-provided configuration.
- Keep `${VAR}` and `${VAR:-fallback}` semantics owned by `sdk-configuration`; do not implement a second YAML substitution parser in Distro code.
- Keep OTLP/gRPC exporter behavior, scoped dependencies, and scoped-dependency bridge behavior intact.
- Treat a DEB filename/package version as insufficient provenance. Record the installed PHP/native embedded versions and scoped `sdk-configuration` version before relying on a target artifact.
- Validate the PHP-originated log resource before the gateway upserts `saas.tenant.id`; gateway output alone is not evidence for PHP-side tenant resolution.

## Tasks

### Task 1: Hydrate the SDK configuration environment before scoped provider initialization

**Objective:** Ensure the scoped SDK configuration reader sees missing process environment variables through `$_SERVER` before Composer autoload can parse declarative configuration and initialize providers.

**Files:**
- Modify: `prod/php/OpenTelemetry/Distro/PhpPartFacade.php:bootstrap` and a narrowly named private helper
- Create or modify: the closest existing PHP unit/component test for `PhpPartFacade` bootstrap/environment behavior
- Modify: `tests/OTelDistroTests/ComponentTests/DeclarativeConfigTest.php` only if it is the existing component boundary for configured logger-provider resource assertions
- Review: `prod/php/bootstrap_php_part.php`, `prod/php/OpenTelemetry/Distro/earlySetup.php`, `tools/build/fix_composer_autoload_order.php`, and the installed scoped `sdk-configuration` `Configuration.php`, `Environment/ServerEnvSource.php`, and `Internal/Substitution.php`
- Validate: source-level missing-key/non-overwrite behavior and a scoped declarative resource using literal `${OTEL_SERVICE_NAME:-php-distro-laravel}` and `${DEPLOYMENT_TENANT_ID:-distro-fixture-tenant}` expressions

**Step 1: Establish expected behaviour**

From the actual failing DEB, record its SHA-512, embedded native/PHP Distro versions, PHP minor, `sdk-configuration` package version, and the reader source used by that package. Confirm that the parser supports `${VAR}` and `${VAR:-fallback}`, and that its default lookup reaches `$_SERVER` but not `getenv()`. Before `PhpPartFacade::registerAutoloaderForVendorDir()` requires scoped Composer autoload files, the Distro must make every process-environment key available in `$_SERVER` only when the key is absent there. Existing `$_SERVER` keys must remain byte-for-byte unchanged.

**Step 2: Add or update validation**

Add focused coverage that sets process values for `OTEL_SERVICE_NAME`, `DEPLOYMENT_TENANT_ID`, and `OTEL_GRPC_ENDPOINT`, removes only their `$_SERVER` entries, invokes the new hydration boundary, and verifies: each missing key is restored from `getenv()`; a pre-existing conflicting `$_SERVER` value is preserved; and unrelated existing entries are unchanged. Add or extend declarative-configuration component coverage using the exact fallback expressions, scoped dependencies enabled, and a configured logger provider; force scoped `Globals::loggerProvider()` and assert its resource contains the E2E service and tenant values. The test must inspect the provider resource directly, not a gateway-mutated signal.

**Step 3: Write minimal implementation**

Implement one small private `PhpPartFacade` helper that obtains the process environment with `getenv()` and adds missing string keys to `$_SERVER` without replacing any existing key. Invoke it after Distro setup is available but before `registerAutoloaderForVendorDir()` can execute scoped `earlySetup.php` and SDK configuration initialization. Do not parse YAML, special-case OpenTelemetry variable names, or modify `sdk-configuration` vendor code. Keep the helper idempotent for repeated bootstrap attempts.

**Step 4: Run test to verify pass**

Run the smallest relevant checks:

`composer run-script run_unit_tests -- --filter 'PhpPartFacade|DeclarativeConfig'`

`composer run-script static_check`

Expected: the focused bootstrap test proves missing-only environment hydration; the scoped logger provider resolves `service.name=php-distro-e2e` and `saas.tenant.id=php-distro-e2e-tenant` from the fallback-expression config; static analysis passes.

**Step 5: Commit**

`git add prod/php/OpenTelemetry/Distro/PhpPartFacade.php tests/OTelDistroTests/ComponentTests/DeclarativeConfigTest.php tests/OTelDistroTests`

`git commit -m "fix: expose process environment to SDK config"`

### Task 2: Validate the repaired scoped package with the full gRPC configuration and Laravel E2E fixture

**Objective:** Prove the packaged Distro resolves all three environment expressions before provider construction and that automatic Laravel logs pass the strict tenant filter without gateway-side masking.

**Files:**
- Modify only if package smoke needs a direct provider-resource assertion: `tools/build/build_packages.sh:test_package` or the narrow package test helper it invokes
- Create only if no existing helper fits: `packaging/test/verify_declarative_resource_environment.sh`
- Review: `packaging/nfpm.yaml`, `packaging/scripts/post-install.sh`, the current Docker Compose service, and the existing gateway resource processor configuration
- Validate: a clean PHP 8.1 image with the PHP gRPC extension, the full supplied `otel-config.yaml`, and the existing `php-distro-laravel` E2E service

**Step 1: Establish expected behaviour**

Build from one clean checkout and record the embedded native/PHP Distro version in the candidate DEB. In a clean PHP 8.1 environment that has the PHP gRPC extension, install the DEB and use the complete supplied configuration: resource fallback expressions plus OTLP/gRPC trace, metric, and log exporters. With the E2E environment set, `OTEL_CONFIG_FILE` must parse successfully, scoped `Globals::loggerProvider()` must be an SDK logger provider rather than `NoopLoggerProvider`, and its direct resource must contain `service.name=php-distro-e2e` and `saas.tenant.id=php-distro-e2e-tenant`.

**Step 2: Add or update validation**

Add a narrow package smoke verifier only if existing smoke tooling cannot inspect the provider resource before export. It must run non-Laravel PHP code, print parser/provider failures, and fail if `${OTEL_SERVICE_NAME}`, `${DEPLOYMENT_TENANT_ID}`, or `${OTEL_GRPC_ENDPOINT}` is unresolved. Do not replace the existing Laravel application or add a new Laravel fixture. In the existing E2E fixture, capture the incoming PHP OTLP resource before the gateway `resource/deployment_tenant` processor, or assert an immutable PHP-only diagnostic resource attribute, so the gateway cannot mask a wrong PHP tenant.

**Step 3: Write minimal implementation**

Wire the verifier into the existing package smoke flow after installation and before general smoke checks, using the same PHP minor and gRPC-enabled image/runtime as the real E2E service. Keep `.sha512` generation after package smoke succeeds. Do not change the gateway processor or its strict tenant filter; adjust only E2E observation so it distinguishes PHP-produced attributes from gateway enrichment.

**Step 4: Run test to verify pass**

From a clean checkout:

`git diff-index --quiet HEAD -- && git -C prod/native diff-index --quiet HEAD --`

`./tools/build/build_native.sh --build_architecture linux-x86-64`

`./tools/build/build_php_code_for_packages.sh --php_versions '81 82 83 84 85'`

`./tools/build/build_packages.sh --package_version '<candidate replacement version>' --build_architecture linux-x86-64 --package_goarchitecture amd64 --package_types 'deb'`

Run the existing `php-distro-laravel` E2E service with its full mounted configuration and two normal `Log::info()` markers.

Expected: the provider and incoming OTLP log resource resolve E2E service/tenant values; the OTLP/gRPC endpoint resolves to `otel-gateway:4317`; both standard Laravel logs pass the unchanged gateway tenant filter; the candidate DEB and `.sha512` exist and verify from `_BUILT/packages`.

**Step 5: Commit**

`git add prod/php/OpenTelemetry/Distro/PhpPartFacade.php tests/OTelDistroTests/ComponentTests/DeclarativeConfigTest.php tools/build/build_packages.sh packaging/test`

`git commit -m "test: verify package config environment resolution"`
