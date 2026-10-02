---
status: planned
created: 2026-10-01
task: 1/1
required_gates:
  e2e_fixture_ownership_resolved: true
  full_raw_warning_reproduced: true
  existing_e2e_request_behavior_preserved: true
  architect_review: false
  memory_update: false
---

# Laravel E2E raw warning capture implementation plan

**Goal:** Preserve the complete native Distro warning text as an E2E diagnostic artifact when the Laravel application is started through `php artisan serve`.

**Architecture:** The reported E2E container runs `php artisan serve`, whose Laravel `ServeCommand::handleProcessOutput()` splits every unrecognised PHP-server line on `] ` and renders only the second segment as a console warning. Distro native logs contain multiple such separators, so that rendered output loses the message. Capture the underlying PHP-server stderr separately from Laravel's formatted console output in the repository-owned E2E fixture; do not change the Distro log format, Laravel vendor code, or warning level.

**Tech Stack:** Docker Compose E2E fixture, Laravel `artisan serve`, PHP built-in server, OpenTelemetry PHP Distro native logger, Docker log artifacts.

**Scope classification:** `single-task`

## Invariants

- Preserve the existing Laravel HTTP start command, port, readiness behavior, and request assertions.
- Preserve the current human-readable `artisan serve` container output; add a raw diagnostic artifact rather than replacing that output.
- Capture the PHP built-in server's original stderr before Laravel `ServeCommand` parses it; do not assert against the shortened `WARN` console rendering.
- Do not modify `prod/native/`, Distro logging configuration/defaults, or `vendor/laravel/**`.
- Do not suppress or repair the unknown warning in this task; its complete text is the input to a later diagnosis.

## Tasks

### Task 1: Add a raw PHP-server stderr artifact to the Laravel E2E fixture

**Objective:** Make a reproduced native warning available in full from the E2E artifacts while retaining the existing Laravel service behavior.

**Files:**
- Modify: the repository-owned Compose/build/entrypoint file that defines the untracked `testing-php-distro-laravel` image and invokes `/usr/local/bin/php-distro-entrypoint` / `php artisan serve` (**blocking: this file is not present in this checkout**)
- Modify: the existing E2E log-collection/upload configuration that publishes the fixture's diagnostics
- Create: a fixture-local raw-stderr file or wrapper only if the owning entrypoint has no existing artifact location
- Review: `tests/OTelDistroTests/ComponentTests/Util/ProcessUtil.php`, `tests/OTelDistroTests/ComponentTests/Util/HttpServerStarter.php`, `tests/OTelDistroTests/ComponentTests/DeclarativeConfigGrpcTest.php`, `tools/test/component/docker_entrypoint.sh`, and `.github/workflows/test-packages-component-tests.yml`
- Validate: the actual `php-distro-laravel` E2E service, one request that reproduces the warning, the retained raw artifact, and the existing service request check

**Step 1: Establish expected behaviour**

Locate and record the tracked repository/branch that owns the reported service definition; this checkout contains no `testing-php-distro-laravel` definition or `php-distro-entrypoint`. Reproduce the warning once and record the raw PHP-server command and file descriptor path. The artifact must retain a complete native line in the form `[OTEL] [timestamp UTC] [pid/tid] [LEVEL] message`, while Docker's existing Laravel-rendered output may continue to show the shortened `WARN [timestamp UTC.` form.

**Step 2: Add or update validation**

Add a narrow fixture-level assertion or post-run verifier that reads the raw artifact after a request and fails when the artifact is absent, empty, or contains a known Distro log prefix without the message segment after its native level delimiter. Use a deterministic test-only warning trigger if one already exists; otherwise retain the artifact without hard-coding the currently unknown warning message. Keep the existing HTTP response/readiness assertion unchanged.

**Step 3: Write minimal implementation**

At the fixture-owned server start boundary, redirect or tee the PHP built-in server's stderr to a mounted/persisted E2E artifact before Laravel's `ServeCommand` output callback consumes it. Use a wrapper/process arrangement that preserves exit status, signal forwarding, and cleanup; do not add a generic collector, alter the application runtime, or patch Laravel vendor code. Publish the artifact through the same log directory or CI artifact mechanism already used for component diagnostics. Reuse the repository pattern where `ProcessUtil::addStdErrOutRedirect()` writes `<debug-name>_stderr_and_stdout.log` when it is applicable to the fixture ownership boundary.

**Step 4: Run test to verify pass**

Run the fixture's existing single-service E2E command after rebuilding its image, then issue the request that previously generated the shortened warning.

Expected: (1) the application response and service lifecycle remain unchanged; (2) regular container logs remain readable; (3) the persisted raw artifact contains the full native warning including its message; and (4) the artifact is retained/uploaded on both successful and failing E2E runs. If the fixture is moved into this repository's component harness, additionally run:

`composer run-script run_component_tests -- --filter 'LaravelAutoInstrumentationTest'`

**Step 5: Commit**

`git add <resolved-fixture-entrypoint-or-compose-file> <resolved-log-collection-or-workflow-file> <new-wrapper-or-verifier-if-needed>`

`git commit -m "test: preserve Laravel E2E warning output"`
