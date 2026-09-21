---
status: planned
created: 2026-09-21
task: 1/6
required_gates:
  architect_review: false
  memory_update: true
  deb_grpc_extension_compatibility: true
  implementation_handoff_confirmation: true
---

# Enable OTLP/gRPC export in the DEB Distro Implementation plan

**Goal:** Make a Debian-installed, scoped OpenTelemetry PHP Distro resolve declarative `otlp_grpc` exporters and export traces to an OTLP/gRPC receiver, while retaining existing OTLP/HTTP behavior.

**Architecture:** Add the gRPC transport as a production Composer dependency so PHP-Scoper carries its implementation, provider metadata, and transitive `grpc/grpc` code into the packaged vendor tree. Make the DEB package depend on a PHP-API-compatible `ext-grpc` provider, then exercise the installed DEB—not source-tree dependencies—against a real OTLP/gRPC receiver. RPM and APK artifacts remain unchanged and are explicitly documented as outside this issue.

**Tech Stack:** PHP 8.1–8.5, Composer/Composer locks, PHP-Scoper, nfpm DEB packaging, Docker component tests, OpenTelemetry Collector OTLP/gRPC receiver.

**Scope classification:** `multi-task`

## Required decision gate

The requested package-managed extension contract is not implementable until it is proven that the package dependency can select an `ext-grpc` build compatible with every PHP API supported by the version-agnostic Distro DEB (8.1–8.5). The current DEB component image uses an official `php:<version>-cli` build, whereas Debian extension packages normally target the distribution PHP build. Before changing production metadata, record the exact package names/repositories and compatibility matrix, or obtain approval to narrow the supported DEB/PHP combinations. Do not silently replace the package-managed contract with a documented prerequisite.

## Tasks

### Task 1: Establish the Debian gRPC extension compatibility contract

**Objective:** Prove and record how a Distro DEB installation obtains an `ext-grpc` binary compatible with each supported PHP minor.

**Files:**
- Modify: `packaging/nfpm.yaml`
- Modify: `tools/test/component/Dockerfile_deb`
- Modify: `docs/reference/supported-technologies.md`
- Validate: DEB installation in the PHP 8.1–8.5 package test matrix

**Step 1: Establish expected behaviour**

For each supported PHP minor in `project.properties`, identify the exact APT package/repository and dependency expression that makes `extension_loaded('grpc')` true for the PHP binary enabled by `post-install.sh`. Confirm that installing the Distro DEB pulls that dependency rather than relying on an image-local PECL install.

**Step 2: Add or update validation**

Extend the DEB test image/setup to install only the prerequisites needed to resolve the Distro package dependency, then add an assertion in the package test path that the installed PHP API has `grpc` loaded.

**Step 3: Write minimal implementation**

Add the verified DEB-only dependency declaration to `packaging/nfpm.yaml` and the matching APT repository/setup to `Dockerfile_deb`. Keep RPM/APK overrides and artifacts untouched. If one static DEB dependency cannot safely cover all supported PHP APIs, stop for the required compatibility decision rather than publishing a partially functional package.

**Step 4: Run test to verify pass**

Run: `./tools/test/component/test_packages_one_matrix_row_in_docker.sh --matrix_row '<each PHP 8.1-8.5 DEB row>' --packages_dir '<built packages>' --logs_dir "$PWD/_BUILT/grpc_deb_logs"`

Expected: each installed DEB resolves its package dependency and the enabled PHP binary reports the `grpc` extension.

**Step 5: Commit**

`git add packaging/nfpm.yaml tools/test/component/Dockerfile_deb docs/reference/supported-technologies.md`

`git commit -m "feat: provide grpc extension for deb distro"`

### Task 2: Ship the scoped gRPC transport dependency

**Objective:** Include a version-compatible `open-telemetry/transport-grpc` and its provider metadata in every production vendor tree.

**Files:**
- Modify: `composer.json`
- Modify: `generated_composer_lock_files/{dev,prod,prod_static_check,test}_{81,82,83,84,85}.lock`
- Validate: `tools/build/generate_composer_lock_files.sh`, `tools/build/build_php_code_for_packages.sh`

**Step 1: Establish expected behaviour**

Choose and pin the transport release resolved with the existing `open-telemetry/exporter-otlp` 1.4.0 and SDK 1.15.0 constraints. The selected package must support PHP 8.1–8.5 and require `ext-grpc`; record the resolved package versions from the regenerated locks.

**Step 2: Add or update validation**

Verify each regenerated production lock contains `open-telemetry/transport-grpc`, `grpc/grpc`, and the expected extension requirement before packaging.

**Step 3: Write minimal implementation**

Add `open-telemetry/transport-grpc` to `require` in `composer.json`, regenerate every committed lock family through the repository generator, and build package PHP code for all supported PHP minors. Do not alter PHP-Scoper configuration unless the generated scoped vendor tree demonstrably omits the transport.

**Step 4: Run test to verify pass**

Run: `./tools/build/generate_composer_lock_files.sh && ./tools/build/build_php_code_for_packages.sh --php_versions '81 82 83 84 85'`

Expected: all locks validate and both scoped and unscoped package vendor outputs contain the gRPC transport and its Composer SPI metadata.

**Step 5: Commit**

`git add composer.json generated_composer_lock_files`

`git commit -m "feat: add grpc transport dependency"`

### Task 3: Assert scoped gRPC factory discovery from the packaged artifact

**Objective:** Detect regressions where the package contains a dependency but the scoped runtime cannot resolve `grpc`.

**Files:**
- Modify: `tests/OTelDistroTests/ComponentTests/PackagesPhpRequirementTest.php` or create a focused package component test beside it
- Validate: `composer run-script run_component_tests -- --filter 'Grpc'`

**Step 1: Establish expected behaviour**

In a process using the installed package with scoped dependencies enabled, `Registry::transportFactory('grpc')` must return the scoped `OTelDistroScoped\OpenTelemetry\Contrib\Grpc\GrpcTransportFactory`, not an unscoped class and not `null`/an unavailable-provider error.

**Step 2: Add or update validation**

Add a focused package-backed assertion that runs in the application host after DEB installation and reports the resolved factory class to the test process.

**Step 3: Write minimal implementation**

Use the existing `ComponentTestCaseBase` and `AppCodeContextUtil::adaptClassNameToScoping()` patterns to load the Distro runtime; avoid adding registry workarounds or manual provider registration.

**Step 4: Run test to verify pass**

Run: `composer run-script run_component_tests -- --filter 'Grpc'`

Expected: the assertion passes with the built/installed DEB and fails if the transport package or scoped provider is missing.

**Step 5: Commit**

`git add tests/OTelDistroTests/ComponentTests`

`git commit -m "test: verify scoped grpc transport discovery"`

### Task 4: Test declarative OTLP/gRPC trace export through a real receiver

**Objective:** Prove that `otlp_grpc` declarative configuration sends a trace over port 4317 from a packaged DEB install.

**Files:**
- Modify: `tools/test/component/docker_compose_external_services.yml`
- Modify: `tools/test/component/external_services_env_vars.sh`
- Modify: `tests/OTelDistroTests/ComponentTests/DeclarativeConfigTest.php`
- Modify: `tests/OTelDistroTests/ComponentTests/TestData/declarative_config_test.yaml` or create a gRPC-specific YAML fixture
- Validate: package component test group requiring external services

**Step 1: Establish expected behaviour**

Run an OpenTelemetry Collector (or equivalent receiver) on the component-test Docker network with an OTLP/gRPC listener on 4317 and an observable trace sink. Configure a trace processor with `otlp_grpc` and an endpoint of `http://<receiver>:4317`; assert receipt of a test span and its service/resource attributes.

**Step 2: Add or update validation**

Add a gRPC-specific component test group so the existing in-process HTTP-only `MockOTelCollector` continues to test HTTP without being repurposed as a gRPC server.

**Step 3: Write minimal implementation**

Add the receiver service, readiness handling, environment variables, and a declarative YAML fixture. Update `DeclarativeConfigTest` (or a narrowly focused sibling) to select the gRPC endpoint and verify the receiver output. Retain the existing `otlp_http` fixture and assertions unchanged.

**Step 4: Run test to verify pass**

Run: `OTEL_PHP_TESTS_GROUP=requires_external_services ./tools/test/component/test_packages_one_matrix_row_in_docker.sh --matrix_row '<DEB matrix row>' --packages_dir '<built packages>' --logs_dir "$PWD/_BUILT/grpc_component_logs"`

Expected: the packaged, scoped Distro exports a trace through OTLP/gRPC on 4317; the existing HTTP declarative component test still passes.

**Step 5: Commit**

`git add tools/test/component tests/OTelDistroTests/ComponentTests`

`git commit -m "test: cover declarative otlp grpc export"`

### Task 5: Document the DEB-only gRPC support contract

**Objective:** Give users an accurate declarative configuration example, prerequisites, limitations, and compatibility boundary.

**Files:**
- Modify: `docs/reference/configuration.md`
- Modify: `docs/reference/supported-technologies.md`
- Validate: documentation review against package behavior and test fixture

**Step 1: Establish expected behaviour**

Document that OTLP/gRPC is opt-in, uses port 4317, needs the package-managed `ext-grpc` dependency, is supported for the proven DEB/PHP matrix only, and is synchronous because background transfer remains OTLP HTTP/protobuf-only.

**Step 2: Add or update validation**

Cross-check the documented endpoint, `otlp_grpc` YAML shape, PHP version range, extension package source, and package-format scope against the compatibility record and passing component test.

**Step 3: Write minimal implementation**

Add a concise `otlp_grpc` declarative trace example without changing the HTTP default endpoint/examples. State RPM/APK non-support for this release and link to the upstream configuration schema for unexpanded signal options.

**Step 4: Run test to verify pass**

Run: `composer run-script static_check`

Expected: PHP checks remain clean; manual review confirms docs do not promise asynchronous gRPC or RPM/APK support.

**Step 5: Commit**

`git add docs/reference/configuration.md docs/reference/supported-technologies.md`

`git commit -m "docs: describe deb otlp grpc support"`

### Task 6: Run release-representative package validation

**Objective:** Validate the changed dependency locks, DEB artifact, gRPC path, and unchanged HTTP path before review.

**Files:**
- Validate: `composer.json`, `generated_composer_lock_files/`, `packaging/`, `tests/`, `docs/`

**Step 1: Establish expected behaviour**

The validation must use generated package artifacts and cover all PHP minors for the new DEB runtime dependency; RPM/APK remain buildable without gRPC changes.

**Step 2: Add or update validation**

Ensure CI/package matrix selection contains a DEB gRPC row for each supported PHP minor, or document a follow-up if the current matrix cannot express that coverage.

**Step 3: Write minimal implementation**

Make only the matrix/workflow adjustment required to execute the new DEB checks; do not expand non-DEB gRPC support.

**Step 4: Run test to verify pass**

Run: `./tools/test/test_php_static_and_unit.sh --php_versions '81 82 83 84 85' --logs_dir "$PWD/_BUILT/unit_tests_logs" && ./tools/build/build_php_code_for_packages.sh --php_versions '81 82 83 84 85' && ./tools/build/build_packages.sh --package_version '<version>' --build_architecture linux-x86-64 --package_goarchitecture amd64 --package_types 'deb rpm apk'`

Expected: static/unit checks, all package builds, DEB gRPC package/component checks, and existing HTTP component coverage pass. Record any Docker-heavy checks not run locally for CI.

**Step 5: Commit**

`git add .github/workflows tools/test project.properties`

`git commit -m "ci: validate deb grpc package support"`
