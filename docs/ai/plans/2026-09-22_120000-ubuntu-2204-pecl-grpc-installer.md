---
status: planned
created: 2026-09-22
task: 1/3
required_gates:
  architect_review: false
  memory_update: true
  ubuntu_2204_installer_contract: true
  release_asset_checksum_verification: true
  implementation_handoff_confirmation: true
---

# Ubuntu 22.04 PECL gRPC installer implementation plan

**Goal:** Provide a GitHub Release-hosted installer that provisions PHP 8.1 and a PECL-built `grpc` extension on Ubuntu 22.04 amd64, then installs a selected OpenTelemetry PHP Distro DEB release for OTLP/gRPC use.

**Architecture:** The Distro DEB remains a normal package and does not install APT dependencies or compile extensions in its maintainer scripts. Restore the version-agnostic DEB package contract by removing its `php8.1-grpc` dependency and the prior DEB-PHP-8.1-only matrix restriction. A release asset `install.sh` performs privileged Ubuntu 22.04 amd64 provisioning before installing the chosen DEB: it validates the host, installs PHP 8.1/PECL build prerequisites with APT, compiles and enables `grpc`, verifies the release DEB checksum, and installs the local artifact. The installer defaults to release `0.7.0` and accepts `--version` for an explicit version.

**Tech Stack:** Ubuntu 22.04 amd64, APT, PHP 8.1, PHP PEAR/PECL, Bash, GitHub Releases, SHA-512, nfpm DEB packaging, Docker component tests.

**Scope classification:** `multi-task`

## Compatibility contract

The installer supports only Ubuntu 22.04 amd64 and must fail before making package changes on another OS, release, or architecture. `--version <semver>` selects a release and defaults to `0.7.0`; asset lookup uses tag `v<version>`. The installer is the only supported OTLP/gRPC provisioning path for this release. It may be invoked after a user downloads it, or through `curl -fsSL <release-install-script-url> | bash -s -- [--version <version>]`; the DEB asset itself must be verified against the matching release SHA-512 asset before installation. RPM/APK gRPC support, auto-selecting a latest release, and installing APT/PECL dependencies in DEB maintainer scripts are out of scope.

## Delivery handoff notes

- Treat commit `7585b96` and any remaining worktree changes that add `php8.1-grpc`, a Sury-only DEB image, or DEB-PHP-8.1-only matrix selection as superseded inputs to Task 1; do not extend that APT-extension contract.
- Retain the Composer gRPC transport dependency. This plan changes only extension provisioning.
- Pin the PECL `grpc` version only after proving it builds and loads with Ubuntu 22.04 PHP 8.1; make the installer idempotent and fail before package changes on unsupported hosts.
- Keep the public installer interface to `--version` and `--help`. Any local release fixture or endpoint override needed by CI must be test-only and must not weaken production release/checksum validation.
- Do not describe `curl | bash` as the checksum-verifiable installation path; document downloading the script and verifying the selected DEB checksum before privileged installation.
- Confirm whether PHP CLI-only provisioning is sufficient or whether PHP-FPM/Apache SAPIs must be provisioned before expanding installer scope.
- Preserve normal DEB/RPM/APK PHP matrix coverage. Only the dedicated Ubuntu 22.04/PHP 8.1 installer-backed gRPC test has the narrowed runtime boundary.

## Tasks

### Task 1: Restore the DEB package and matrix contract

**Objective:** Remove the invalid APT gRPC dependency and return ordinary DEB package/test coverage to the supported PHP matrix before introducing the Ubuntu-specific installer path.

**Files:**
- Modify: `packaging/nfpm.yaml`
- Modify: `tools/test/component/Dockerfile_deb`
- Modify: `tools/test/component/docker_entrypoint.sh` if its APT local-package installation was added solely for `php8.1-grpc`
- Modify: `tools/test/component/generate_matrix.sh`
- Modify: `tests/OTelDistroTests/UnitTests/UtilTests/ComponentTestsMatrixUnitTest.php`
- Modify: `tests/OTelDistroTests/ComponentTests/PackagesPhpRequirementTest.php`
- Validate: generated component matrix and existing package component test selection

**Step 1: Establish expected behaviour**

The DEB must not depend on `php8.1-grpc` or any external runtime prerequisite for normal installation. DEB, RPM, and APK retain the repository's existing PHP-version package matrix; the Ubuntu 22.04/PHP 8.1/PECL gRPC contract is exercised only by a dedicated installer test.

**Step 2: Add or update validation**

Restore the matrix generator and its mirrored unit expectation so the original DEB multi-PHP selection is present. Remove the package-host gRPC-loaded assertion, because ordinary package tests do not provision gRPC; retain scoped gRPC factory coverage only where the installer has provided the extension.

**Step 3: Write minimal implementation**

Remove the DEB-specific `php8.1-grpc` dependency and undo only the Sury/PHP-8.1-only test-image and APT-install changes introduced for that dependency. Keep the Composer gRPC transport dependency and its provider discovery coverage. Do not change DEB maintainer scripts to install repositories, packages, or PECL extensions.

**Step 4: Run test to verify pass**

Run: `./tools/test/component/generate_matrix.sh && composer run-script run_unit_tests -- --filter 'ComponentTestsMatrixUnitTest'`

Expected: the generated matrix contains the original supported DEB/PHP rows; no normal package test requires `grpc` to be loaded.

**Step 5: Commit**

`git add packaging/nfpm.yaml tools/test/component tests/OTelDistroTests/ComponentTests/PackagesPhpRequirementTest.php tests/OTelDistroTests/UnitTests/UtilTests/ComponentTestsMatrixUnitTest.php`

`git commit -m "fix: restore deb runtime dependency contract"`

### Task 2: Add the Ubuntu 22.04 release installer

**Objective:** Create a deterministic user-facing installer that provisions PHP 8.1 and PECL gRPC, verifies a selected release DEB, and installs it on Ubuntu 22.04 amd64.

**Files:**
- Create: `tools/install/install.sh`
- Create: `tools/install/test_install.sh` or a focused shell test beside the installer
- Modify: `.github/workflows/release.yml`
- Validate: Ubuntu 22.04 Docker installation using a built/release-shaped DEB asset

**Step 1: Establish expected behaviour**

`install.sh` accepts only `--version <semver>` and `--help`; no version means `0.7.0`. It rejects malformed/unsafe versions, non-root execution unless it can re-exec with `sudo`, non-Ubuntu-22.04 hosts, and non-amd64 hosts before it changes the system. It downloads `opentelemetry-php-distro_<version>_amd64.deb` and its matching SHA-512 file from GitHub Release tag `v<version>`.

**Step 2: Add or update validation**

Add a focused Ubuntu 22.04 test that runs the installer against a release-shaped local fixture or test release endpoint, then asserts `php -r "var_export(extension_loaded('grpc'));"` reports true, the selected Distro DEB is installed, and the Distro loader is enabled. Test invalid platform, architecture, version, and checksum paths without making system changes.

**Step 3: Write minimal implementation**

Implement `tools/install/install.sh` with strict shell mode. Install the exact Ubuntu 22.04 PHP 8.1, PHP development, PEAR/PECL, compiler, and library prerequisites needed to build `grpc`; choose and pin the compatible PECL gRPC release, compile it, enable it for PHP 8.1 CLI, and verify it loads. Download the DEB/checksum with `curl -fsSL`, verify SHA-512 before `apt install` of the local artifact, and leave RPM/APK untouched. Update the release workflow to upload the versioned installer asset together with the DEB/checksum assets, and verify that asset is downloadable from the draft release.

**Step 4: Run test to verify pass**

Run: `<Ubuntu 22.04 installer test command> --version 0.7.0`

Expected: an Ubuntu 22.04 amd64 test image provisions PECL `grpc`, verifies the DEB checksum, installs the requested DEB, and reports both `extension_loaded('grpc')` and the Distro loader as true.

**Step 5: Commit**

`git add tools/install .github/workflows/release.yml`

`git commit -m "feat: add ubuntu grpc installer"`

### Task 3: Verify installer-backed OTLP/gRPC and document the contract

**Objective:** Prove the installed Ubuntu 22.04 artifact exports through OTLP/gRPC and give users accurate, reproducible installation instructions.

**Files:**
- Modify: `tools/test/component/docker_compose_external_services.yml`
- Modify: `tools/test/component/external_services_env_vars.sh`
- Modify: `tests/OTelDistroTests/ComponentTests/DeclarativeConfigGrpcTest.php`
- Modify: `tests/OTelDistroTests/ComponentTests/TestData/declarative_config_grpc_test.yaml`
- Modify: `docs/getting-started/setup.md`
- Modify: `docs/reference/configuration.md`
- Modify: `docs/reference/supported-technologies.md`
- Validate: Ubuntu 22.04 installer-backed package component test with external services

**Step 1: Establish expected behaviour**

After installer provisioning, declarative `otlp_grpc` sends a trace to the real OTLP/gRPC receiver on port 4317. Existing HTTP declarative coverage remains unchanged. Docs show the release-hosted installer invocation, `--version` default/override semantics, Ubuntu 22.04 amd64 boundary, synchronous transfer limitation, and RPM/APK non-support.

**Step 2: Add or update validation**

Run the existing Jaeger-based gRPC test only in the dedicated Ubuntu 22.04 installer-backed DEB row. Assert receipt of the test span and documented resource attributes; retain the in-process HTTP mock collector test. Add a negative installer test for an invalid checksum or unsupported platform.

**Step 3: Write minimal implementation**

Retarget the gRPC component host from the Sury-specific DEB image to Ubuntu 22.04 and invoke the installer with a local/release-shaped asset source suitable for CI. Keep the OTLP receiver and YAML fixture only if they remain the minimal real-receiver coverage. Update docs to replace `php8.1-grpc` APT-dependency language with the installer contract; do not describe `curl | bash` as checksum-verifiable unless users use the documented download-and-verify flow.

**Step 4: Run test to verify pass**

Run: `OTEL_PHP_TESTS_GROUP=requires_external_services ./tools/test/component/test_packages_one_matrix_row_in_docker.sh --matrix_row '<Ubuntu 22.04 amd64 installer-backed DEB row>' --packages_dir '<built packages>' --logs_dir "$PWD/_BUILT/ubuntu_grpc_component_logs"`

Expected: the installer-backed, packaged Distro exports a trace through OTLP/gRPC on 4317; the existing HTTP declarative component test still passes.

**Step 5: Commit**

`git add tools/test/component tests/OTelDistroTests/ComponentTests docs/getting-started/setup.md docs/reference`

`git commit -m "docs: describe ubuntu grpc installer"`
