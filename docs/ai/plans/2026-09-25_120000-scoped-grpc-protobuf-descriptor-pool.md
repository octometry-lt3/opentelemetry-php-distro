---
status: planned
created: 2026-09-25
task: 1/3
required_gates:
  baseline_provenance: true
  release_version: true
  user_run_installer_backed_component_test: true
  architect_review: false
  memory_update: false
---

# Scoped OTLP/gRPC protobuf descriptor-pool repair implementation plan

**Goal:** Make the scoped Debian PHP runtime deserialize OTLP/gRPC trace export responses without protobuf descriptor-pool errors, and release the repair as a new immutable amd64 DEB.

**Architecture:** The package build installs a generated production lock, copies the vendor tree, scopes it with PHP-Scoper, then applies scoped Composer-autoload repairs before copying it to the package payload. Generated OTLP protobuf classes and their `GPBMetadata` descriptor registrations must agree on their scoped PHP class names; the repair must preserve the scoped dependency boundary rather than delegate protobuf dependencies to the application. The existing installer-backed Ubuntu 22.04/PHP 8.1 gRPC component row is the end-to-end contract because it uses the built package artifact and a real gRPC response.

**Tech Stack:** PHP 8.1, PHP-Scoper, Composer generated locks, `google/protobuf`, `grpc/grpc`, PECL `grpc-1.66.0`, OTLP/gRPC, Docker package component tests, DEB/nfpm.

**Scope classification:** `multi-task`

## Invariants

- Keep `OTEL_PHP_SCOPED_DEPS_ENABLED=true`; do not require application Composer dependencies or turn off dependency scoping.
- Preserve the installer/PECL-only Ubuntu 22.04 amd64 PHP 8.1 gRPC contract; do not add `php8.1-grpc` to `packaging/nfpm.yaml`.
- Package/component validation must install and exercise the built DEB, not the source-tree vendor directory.
- Do not alter an existing release artifact. The release version must advance before packaging and the tag must remain `v${version}`.

## Tasks

### Task 1: Establish a package-backed descriptor-response regression

**Objective:** Reproduce the descriptor-pool error against the exact package source/artifact baseline and make a real gRPC response deserialization failure observable in automated component coverage.

**Files:**
- Modify: `tests/OTelDistroTests/ComponentTests/DeclarativeConfigGrpcTest.php`
- Review/modify only if required: `tests/OTelDistroTests/ComponentTests/TestData/declarative_config_grpc_test.yaml`
- Review/modify only if required: `tests/OTelDistroTests/ComponentTests/PackagesPhpRequirementTest.php`
- Validate: `tools/test/component/test_packages_one_matrix_row_in_docker.sh`

**Step 1: Establish expected behaviour**

Record the source commit, package version, and DEB checksum used to reproduce the reported `ExportTraceServiceResponse` descriptor-pool failure. The app-code process must obtain the scoped provider, create a named span, and flush it so the gRPC client receives and deserializes an actual collector response; a process that merely initializes Laravel or exits successfully is insufficient.

**Step 2: Add or update validation**

Extend the existing installer-backed DEB PHP 8.1 test input to assert the scoped tracer provider, create an explicit application span, force flushing, and verify the Jaeger/collector result. Preserve the declarative batch `otlp_grpc` configuration and add focused coverage of the scoped generated response class/descriptor relationship only when it reproduces the same production failure without bypassing the gRPC client.

**Step 3: Write minimal implementation**

Keep the row restricted to the installer-backed Ubuntu 22.04 amd64 PHP 8.1 DEB environment. Surface child-process stderr in the test failure context so a protobuf descriptor exception is distinguishable from missing transport registration or a no-op provider.

**Step 4: Run test to verify pass**

Run focused syntax checks, then ask the user to run after building package artifacts:

`OTEL_PHP_TESTS_GROUP=requires_external_services OTEL_PHP_TESTS_INSTALLER_BACKED=true OTEL_PHP_LOG_LEVEL_STDERR=DEBUG ./tools/test/component/test_packages_one_matrix_row_in_docker.sh --matrix_row '8.1,deb,cli,with_ext_svc' --packages_dir "$PWD/_BUILT/packages" --logs_dir "$PWD/_BUILT/ubuntu_grpc_component_logs"`

Expected: a real named span is received by the local OTLP/gRPC receiver, with no protobuf descriptor-pool exception.

**Step 5: Commit**

`git add tests/OTelDistroTests/ComponentTests/DeclarativeConfigGrpcTest.php tests/OTelDistroTests/ComponentTests/TestData/declarative_config_grpc_test.yaml tests/OTelDistroTests/ComponentTests/PackagesPhpRequirementTest.php`

`git commit -m "test: cover scoped grpc response descriptors"`

### Task 2: Align scoped generated protobuf descriptors with their PHP classes

**Objective:** Make PHP-Scoper output register OTLP generated message descriptors under the same scoped class names used by the gRPC client.

**Files:**
- Modify: `tools/build/php-scoper.inc.php`
- Review/modify only if required: `tools/build/fix_scoped_composer_autoload.php`
- Review/modify only if required: `tools/build/fix_composer_autoload_order.php`
- Validate: `tools/build/build_php_code_for_packages.sh:366-405`
- Validate: `tests/OTelDistroTests/ComponentTests/DeclarativeConfigGrpcTest.php`

**Step 1: Establish expected behaviour**

Inspect the unscoped and scoped PHP 8.1 vendor outputs for `Opentelemetry\\Proto\\Collector\\Trace\\V1\\ExportTraceServiceResponse`, its generated `GPBMetadata\\Opentelemetry` metadata, and the descriptor-pool lookup performed when the gRPC response is decoded. Determine and document the exact mismatch: class declaration, metadata bootstrap reference, serialized descriptor PHP namespace, or autoload order. Do not assume provider registration and descriptor registration are the same defect.

**Step 2: Add or update validation**

Add a narrow, generated-vendor fixture or temporary copied vendor fixture that proves the chosen Scoper/post-processing rule is applied to both generated message classes and descriptor metadata, is idempotent, and leaves unscoped vendor output unchanged. Retain Task 1 as the end-to-end regression test.

**Step 3: Write minimal implementation**

Add the smallest explicit PHP-Scoper patcher or post-scoping repair that makes descriptor registration and lookup use the scoped `OTelDistroScoped\\Opentelemetry\\Proto` class names. Apply it only to generated OTLP protobuf/metadata files needed by the package, retain the existing excluded user-facing namespaces and Composer collision rehashing, and fail clearly if the expected upstream generated-code shape changes.

**Step 4: Run test to verify pass**

Run: `php -l tools/build/php-scoper.inc.php && php -l tools/build/fix_scoped_composer_autoload.php && php -l tools/build/fix_composer_autoload_order.php`

Then build the PHP package payload for PHP 8.1 and run the Task 1 installer-backed component row.

Expected: the scoped response class is registered in the protobuf descriptor pool, the gRPC response decodes, and the receiver observes the exported span.

**Step 5: Commit**

`git add tools/build/php-scoper.inc.php tools/build/fix_scoped_composer_autoload.php tools/build/fix_composer_autoload_order.php tests/OTelDistroTests/ComponentTests`

`git commit -m "fix: register scoped grpc protobuf descriptors"`

### Task 3: Release a new immutable Debian artifact

**Objective:** Package the verified fix as a new versioned Ubuntu 22.04 amd64 DEB without changing the existing `0.7.0` artifact.

**Files:**
- Modify: `project.properties`
- Modify: release notes/version files identified by the repository release workflow
- Review/modify only if required: `generated_composer_lock_files/*_81.lock`
- Validate: `tools/build/build_packages.sh`
- Validate: package filename and checksum under `_BUILT/packages/`

**Step 1: Establish expected behaviour**

Obtain the next approved SemVer release version. Confirm that no Composer constraint changed before modifying lock files; descriptor-scoping-only changes must not trigger unrelated lock refreshes.

**Step 2: Add or update validation**

Verify the release-version invariant from `project.properties` through the release workflow and ensure the produced DEB filename and SHA-512 checksum identify the new version, architecture, and immutable contents.

**Step 3: Write minimal implementation**

Update the central release version and only required release documentation. Build the PHP package payload and a `deb` package for `linux-x86-64`/`amd64`; do not overwrite or republish `0.7.0`.

**Step 4: Run test to verify pass**

Run:

`./tools/build/build_php_code_for_packages.sh --php_versions '81'`

`./tools/build/build_packages.sh --package_version <approved-new-version> --build_architecture linux-x86-64 --package_goarchitecture amd64 --package_types 'deb'`

Then install that exact new DEB in the Task 1 Ubuntu 22.04/PHP 8.1/PECL-gRPC test path and rerun the installer-backed component row.

Expected: a new versioned amd64 DEB and checksum are produced; both CLI/FPM package configuration is retained; the real gRPC trace export passes without descriptor errors.

**Step 5: Commit**

`git add project.properties docs/release-notes generated_composer_lock_files`

`git commit -m "chore: prepare <approved-new-version> release"`
