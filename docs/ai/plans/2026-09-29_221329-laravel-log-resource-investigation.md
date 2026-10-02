---
status: planned
created: 2026-09-29
task: 1/1
required_gates:
  exact_package_provenance: true
  package_backed_laravel_log_reproduction: true
  scoped_provider_identity_evidence: true
  architect_review: false
  memory_update: false
---

# Laravel automatic-log resource investigation plan

**Goal:** Prove which logger provider emits automatic Laravel records from Distro `0.7.0~lt3.3`, why its resource differs from the configured global resource, and identify the smallest repository-owned repair point without changing application or gateway behavior.

**Architecture:** The Distro bootstrap selects the scoped runtime, loads its Composer files autoload, and invokes `PhpPartFacade::earlySetup()` before SDK and instrumentation autoload entries. The locked Laravel auto-instrumentation constructs one scoped `CachedInstrumentation`; its `logger()` method resolves `Globals::loggerProvider()` at each use and caches loggers by provider object. Therefore a cached instrumentation object alone cannot retain an earlier provider after global-provider replacement; the investigation must compare the actual provider identity and resource at Laravel log emission with the scoped global provider and the configured trace/meter providers.

**Tech Stack:** PHP 8.1 package runtime, PHP-Scoper, OpenTelemetry API 1.10.0, SDK 1.15.0, SDK Configuration 0.9.0, Laravel auto-instrumentation 1.9.0, OTLP logs, Docker package component tests.

**Scope classification:** `single-task`

## Invariants

- Do not add an application-owned SDK/exporter, inject the tenant into Laravel log context, or alter the gateway tenant filter.
- Keep scoped dependencies, the scoped-dependency bridge, and OTLP/gRPC behavior intact.
- Treat `0.7.0~lt3.3` as an immutable artifact: inspect its embedded vendor sources, generated Composer autoload files, and package metadata rather than inferring them from the repository's `0.7.0` version alone.
- Do not accept a trace-only assertion as proof for this defect; inspect the resource carried by the emitted OTLP log record.

## Tasks

### Task 1: Establish package-backed Laravel logger-provider provenance

**Objective:** Reproduce one standard `Log::info()` emission from the pinned package and determine whether its logger provider/resource is the scoped configured global or a distinct runtime/configuration instance.

**Files:**
- Modify only if the package-backed regression harness is needed: `tests/OTelDistroTests/ComponentTests/LaravelAutoInstrumentationTest.php`
- Modify only if log payload decoding is needed: `tests/OTelDistroTests/ComponentTests/Util/MockOTelCollector.php`
- Create only if needed: `tests/OTelDistroTests/ComponentTests/Util/OtlpData/ExportLogsServiceRequest.php` and the minimal resource/scope/log-record DTOs it requires
- Review: `prod/php/bootstrap_php_part.php`, `prod/php/OpenTelemetry/Distro/PhpPartFacade.php`, `prod/php/OpenTelemetry/Distro/ScopedDepsBridge.php`, `tools/build/fix_composer_autoload_order.php`, `tools/build/php-scoper.inc.php`
- Validate: the exact installed package's `scoped/81/vendor/composer/autoload_{files,static}.php`, Laravel `LogWatcher.php`, `CachedInstrumentation.php`, and the package-backed Laravel test command

**Step 1: Establish expected behaviour**

Obtain the exact `0.7.0~lt3.3` package (including checksum, PHP minor, architecture, and its embedded Composer lock/source references) and the external fixture configuration that reproduces the report. In a single persistent Laravel worker with `DEPLOYMENT_TENANT_ID=php-distro-e2e-tenant` and `OTEL_SERVICE_NAME=php-distro-e2e`, emit the marker through the standard Laravel `Log` facade and capture its OTLP `ResourceLogs` payload. It must contain `service.name=php-distro-e2e` and `saas.tenant.id=php-distro-e2e-tenant`; retain the marker body and `request.correlation` as record-level evidence.

**Step 2: Add or update validation**

If no existing package test can decode OTLP logs, extend the component collector narrowly to deserialize `/v1/logs` and expose resource logs for assertions; it currently acknowledges logs without deserializing them. Extend the existing Laravel auto-instrumentation test to emit a normal framework log after application construction, provide a declarative `logger_provider` OTLP processor alongside the resource configuration, and assert the log body, correlation attribute, instrumentation scope, and both resource attributes. Run the assertion against an installed built package artifact, not a source-tree vendor directory.

**Step 3: Write minimal implementation**

Make no production repair until the following evidence is recorded from the failing worker: the class and `spl_object_id` of (a) the provider returned by scoped `Globals::loggerProvider()`, (b) the provider selected by the `LogWatcher`'s `CachedInstrumentation`, and (c) the trace and meter providers; plus the resource attributes held by each. Inspect autoload order and scoped/unscoped class names in the installed artifact. Classify the result before opening a repair task:

1. **Same scoped logger provider, wrong resource:** repair declarative configuration/resource construction or its environment source/lifecycle; do not modify `CachedInstrumentation`.
2. **Different scoped/unscoped providers:** repair the specific package bootstrap, Scoper, or scoped-bridge boundary that permits a second `Globals` instance; retain the single shared scoped runtime.
3. **Correct SDK log resource before export, altered later:** stop this Distro-runtime plan and supply evidence identifying the external collector/fixture transformation, because the stated fix boundary excludes the gateway filter.

The known locked sources already rule out a simple stale-cache theory: Laravel 1.9.0 calls `CachedInstrumentation::logger()` at record time, and API 1.10.0 keys its logger cache by the current provider returned from `Globals::loggerProvider()`.

**Step 4: Run test to verify pass**

Run focused source coverage while developing the decoder:

`composer run-script run_component_tests -- --filter 'LaravelAutoInstrumentationTest|DeclarativeConfigTest'`

Then build/install the exact target package (or a new candidate package after a later repair) and run its matching Docker package matrix row:

`./tools/test/component/test_packages_one_matrix_row_in_docker.sh --matrix_row '<target PHP minor,package type,mode,service row>' --packages_dir "$PWD/_BUILT/packages" --logs_dir "$PWD/_BUILT/laravel_log_resource_logs"`

Expected: the captured automatic Laravel OTLP log has the two environment-resolved resource values; its provider identity explains any failure unambiguously. If the pinned artifact still fails, this task is complete only with one of the three classifications above and a repository path/symbol for a separate repair plan.

**Step 5: Commit**

If test infrastructure was added:

`git add tests/OTelDistroTests/ComponentTests/LaravelAutoInstrumentationTest.php tests/OTelDistroTests/ComponentTests/Util/MockOTelCollector.php tests/OTelDistroTests/ComponentTests/Util/OtlpData`

`git commit -m "test: capture Laravel OTLP log resources"`

Otherwise do not create a product commit; record the package provenance and classification in the follow-up repair issue/plan.
