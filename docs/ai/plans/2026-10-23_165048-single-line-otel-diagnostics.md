---
status: planned
created: 2026-10-23
task: 1/1
required_gates:
  architect_review: false
  memory_update: false
---

# Single-line OpenTelemetry SDK diagnostics implementation plan

**Goal:** Emit each distro-routed OpenTelemetry SDK diagnostic as one physical log line, with its appended context formatted as compact JSON while retaining the existing `[OTEL]` text prefix.

**Architecture:** Change only the PHP adapter that converts SDK diagnostics into native logger messages. Keep native sink formatting and OTLP telemetry log records unchanged. Preserve the existing opt-out and ensure newlines in message/context cannot turn one diagnostic into multiple line-oriented collector entries.

**Tech Stack:** PHP 8.1–8.5, OpenTelemetry SDK internal `LogWriterInterface`, distro native `log_feature()`, PHPUnit, Composer static checks.

**Scope classification:** `single-task`

## Intent and evidence

- The reported warning is an `OTEL` SDK diagnostic containing `trace_id`, `span_id`, `attributes`, `links`, `link_attributes`, `events`, and `event_attributes`; there is no client raw log available, so the SDK warning trigger and collector behavior are not independently verified.
- `prod/php/OpenTelemetry/Distro/PhpPartFacade.php:120,289-292` registers `NativeLogWriter`. `prod/php/OpenTelemetry/Distro/Log/NativeLogWriter.php:19,25-46` defaults `OTEL_PHP_LOG_OTEL_WITH_CONTEXT` to true and appends `var_export($context, true)` to the SDK message. PHP `var_export` renders arrays across physical lines.
- `prod/native/extension/code/ModuleFunctions.cpp:91-97` forwards the message to the logger without splitting it. `prod/native/libcommon/code/Logger.cpp:60-96,166-169` appends one terminal newline and writes the whole formatted output, including any embedded newlines, to stderr. A downstream line-oriented collector can therefore render each context line as a distinct entry; do not mistake those lines for separate OTLP log records.
- Local, read-only reproduction with seven representative fields: `var_export($context, true)` introduced **8 embedded newlines**, while `json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)` introduced **0** and printed `{"trace_id":"0123456789abcdef","span_id":"01234567","attributes":1,"links":0,"link_attributes":0,"events":0,"event_attributes":0}`. This reproduces formatting, not the SDK's attribute-dropping condition.
- Existing compact JSON precedent: `LogBackend::contextToText()` in `prod/php/OpenTelemetry/Distro/Log/LogBackend.php:86-99`; it substitutes invalid UTF-8 and handles encoding failures, but its failure fallback is *plain text*, not JSON. Preserve valid JSON in the SDK diagnostic context even on encoding failure; do not blindly reuse that fallback if JSON is a strict requirement.

## Tasks

### Task 1: Keep SDK diagnostic context as one JSON-formatted line

**Objective:** Stop physical line breaks in distro-routed SDK diagnostics without changing the meaning, severity, source location, or native logger's existing output format.

**Files:**
- Modify: `prod/php/OpenTelemetry/Distro/Log/NativeLogWriter.php`
- Create: `tests/OTelDistroTests/UnitTests/UtilTests/ProdLogTests/NativeLogWriterTest.php`
- Modify: `docs/reference/configuration.md` (logging table, if documenting the existing context toggle)
- Validate: `composer run-script static_check`; `composer run-script run_unit_tests -- --filter 'NativeLogWriterTest'`

**Step 1: Establish expected behaviour**

An SDK warning with a nonempty context should retain its current `[OTEL]` prefix, level, and message and append ` context: {"trace_id":..., ...}` as compact valid JSON, not PHP `array (...)`. The entire message sent to `log_feature()` must have no literal `\r` or `\n` (including when the SDK message or a context value contains them). Empty context and `OTEL_PHP_LOG_OTEL_WITH_CONTEXT=false` should preserve their existing behavior (currently, an enabled empty context appends ` context: array (\n)`; replacing that with compact `context: []` is acceptable). Encoding failures must not throw from the log writer or silently suppress the original warning; retain a single-line, valid-JSON error placeholder as context. This is diagnostic formatting only: do not adjust SDK span limits or claims about whole traces being dropped.

**Step 2: Add or update validation**

Use the adjacent `ProdLogTests` PHPUnit pattern. Exercise the actual message-building logic through the narrowest testable seam without starting the native extension; extract only a small formatter from `NativeLogWriter` if needed, rather than creating a new logging abstraction. Assert parseable appended JSON and zero physical line breaks for representative SDK warning fields, strings containing newline/carriage return, invalid UTF-8, and a recursive/non-JSON-encodable value. Cover the disabled-context setting and verify the warning text is retained on encoding failure. If an installed packaged runtime is available, additionally compare raw stderr against line-oriented collection for one SDK diagnostic; do not require a Docker matrix to verify this PHP-only change.

**Step 3: Write minimal implementation**

Replace `var_export($context, true)` in `NativeLogWriter::write()` with compact JSON serialization using the existing `LogBackend::contextToText()` encoding flags/behavior where applicable; ensure the failure path still outputs valid JSON. Escape any residual literal CR/LF in the *final message* to printable `\\r` / `\\n` so the native logger's one terminal newline remains its only physical delimiter. Do not restructure the whole diagnostic as JSON: the user explicitly chose JSON **context only**. Preserve `OTEL_PHP_LOG_OTEL_WITH_CONTEXT` default and behavior when disabled, and optionally document this existing option alongside the logging settings.

**Step 4: Run test to verify pass**

Run: `composer run-script run_unit_tests -- --filter 'NativeLogWriterTest' && composer run-script static_check`

Expected: focused tests pass; PHP static checks pass; sample warning emits exactly one physical line with JSON context and no PHP array formatting. If local dependencies are absent, prepare them with `./tools/build/install_PHP_deps_in_dev_env.sh` (requires Docker), **not** ordinary `composer install`/`update`; report any validation skipped for lack of Docker.

**Step 5: Commit (implementation handoff only, not during planning)**

`git add prod/php/OpenTelemetry/Distro/Log/NativeLogWriter.php tests/OTelDistroTests/UnitTests/UtilTests/ProdLogTests/NativeLogWriterTest.php docs/reference/configuration.md`

`git commit -m "fix: keep OTEL SDK diagnostics on one line"`
