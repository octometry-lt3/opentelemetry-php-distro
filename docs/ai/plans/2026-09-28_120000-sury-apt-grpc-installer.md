---
status: planned
created: 2026-09-28
task: 3/3
required_gates:
  architect_review: true
  memory_update: true
  external_apt_repository_approval: true
  release_version: true
  user_run_installer_backed_component_test: true
---

# Sury APT gRPC installer provisioning implementation plan

**Goal:** Replace PECL-built gRPC with the `php8.1-grpc` package from the approved Ondřej Surý Jammy PPA in the Ubuntu 22.04 amd64 installer-backed gRPC path, while keeping the Distro DEB free of a gRPC package dependency.

**Architecture:** The release installer, rather than the DEB maintainer scripts, owns adding the approved external APT source and provisioning the PHP 8.1 gRPC extension. It must install the PPA signing key into a dedicated keyring, configure only the Jammy PPA source with `signed-by`, install and enable `php8.1-grpc`, and verify the exact PHP 8.1 CLI runtime can load it. Scoped `open-telemetry/transport-grpc` remains a packaged Composer dependency; provider registration and real OTLP/gRPC export remain separate end-to-end checks.

**Tech Stack:** Ubuntu 22.04, APT, GnuPG keyring, Ondřej Surý PHP PPA, PHP 8.1, `php8.1-grpc`, Composer `open-telemetry/transport-grpc`, scoped PHP dependencies, Docker installer-backed component tests, DEB/nfpm.

**Scope classification:** `multi-task`

## Invariants

- The Distro DEB must not declare or install `php8.1-grpc`; external APT repository setup and gRPC provisioning belong only to the documented Ubuntu installer path.
- Retain `open-telemetry/transport-grpc` as a pinned, scoped production Composer dependency; do not require application Composer dependencies or disable dependency scoping.
- Use only the approved PPA key fingerprint `14AA40EC0831756756D7F66C4F4EA0AAE5267A6C` and a dedicated `signed-by` keyring; do not use `apt-key`, a global trusted keyring, or an unverified key download.
- Limit this contract to Ubuntu 22.04 amd64 and PHP 8.1. Do not imply that RPM, APK, or other PHP minors receive the PPA-provided gRPC extension.
- Package/component validation must install and exercise the built DEB via the installer, not a source-tree vendor directory or an image-local gRPC extension.
- Do not alter an existing release artifact. A release version must advance before publishing the changed installer and DEB.

## Tasks

### Task 1: Record the external APT repository contract

**Objective:** Make the PPA trust, ownership, platform boundary, and PECL replacement decision durable before changing installer behavior.

**Files:**
- Create: `docs/ai/decisions/2026-09-28-sury-apt-grpc-installer.md`
- Modify: `docs/getting-started/setup.md`
- Modify: `docs/reference/configuration.md`
- Review: `docs/ai/plans/2026-09-22_120000-ubuntu-2204-pecl-grpc-installer.md`
- Review: `docs/ai/plans/2026-09-25_120000-scoped-grpc-protobuf-descriptor-pool.md`

**Step 1: Establish expected behaviour**

Record that Ubuntu Jammy's official archives do not supply `php8.1-grpc`; the supported installer path intentionally trusts the approved Ondřej Surý Jammy PPA instead of compiling PECL gRPC. Confirm the DEB remains independent of this repository and gRPC remains opt-in through the Ubuntu 22.04 amd64/PHP 8.1 installer.

**Step 2: Add or update validation**

Review the installer documentation against the actual source/keyring path, package name, platform preflight, and post-install checks. Ensure it tells users that the installer adds the external PPA and does not claim the package comes from Ubuntu's official archives.

**Step 3: Write minimal implementation**

Add a concise ADR describing the selected PPA, exact signing-key fingerprint, installer ownership, rejected PECL and DEB-dependency alternatives, and support consequences. Update only the affected setup and configuration guidance to replace PECL build-prerequisite language with the external-APT installer contract.

**Step 4: Run test to verify pass**

Run: `git diff --check`

Expected: the public and durable documentation agree that the installer, not the DEB, configures the approved external repository and installs PHP 8.1 gRPC.

**Step 5: Commit**

`git add docs/ai/decisions/2026-09-28-sury-apt-grpc-installer.md docs/getting-started/setup.md docs/reference/configuration.md`

`git commit -m "docs: record apt grpc installer contract"`

### Task 2: Provision and verify PPA gRPC in the installer

**Objective:** Replace the PECL compilation path with reproducible PPA-backed installation and activation of `php8.1-grpc`.

**Files:**
- Modify: `tools/install/install.sh`
- Modify: `tools/install/test_install.sh`
- Review/modify only if required: `tools/test/component/Dockerfile_deb_installer`
- Review: `packaging/nfpm.yaml`

**Step 1: Establish expected behaviour**

In a clean Ubuntu 22.04 amd64 environment, confirm the installer preflight passes, verifies the approved PPA signing key fingerprint before writing the source, and can install a candidate `php8.1-grpc` package for the PHP 8.1 CLI runtime. Confirm `packaging/nfpm.yaml` remains free of `php8.1-grpc`.

**Step 2: Add or update validation**

Extend the installer fixture test to prove the installed runtime reports `extension_loaded('grpc') === true`, `php8.1 --ri grpc` succeeds, and the installed version is recorded in failure output. Retain the installer test's local release-shaped asset, checksum, OS, architecture, and idempotent-install coverage.

**Step 3: Write minimal implementation**

Remove `GRPC_VERSION`, `pecl install`, and PECL-only build prerequisites from the installer. Before installing PHP 8.1 packages, install only the tools required to fetch and dearmor the key, write `/usr/share/keyrings/ppa_ondrej_php.gpg`, verify fingerprint `14AA40EC0831756756D7F66C4F4EA0AAE5267A6C`, and write `/etc/apt/sources.list.d/ppa_ondrej_php.list` with `signed-by` and the Jammy PPA URL. Refresh APT, install `php8.1-cli` and `php8.1-grpc`, enable the module with `phpenmod -v 8.1 grpc`, and fail clearly unless the PHP 8.1 CLI loads it. Do not add repository setup or gRPC dependencies to the DEB or its maintainer scripts.

**Step 4: Run test to verify pass**

Run: `bash -n tools/install/install.sh tools/install/test_install.sh`

Then run after building a DEB artifact: `./tools/install/test_install.sh --deb "$PWD/_BUILT/packages/<new-versioned-amd64-deb>"`

Expected: the installer verifies the trusted key, installs/enables PPA `php8.1-grpc` for PHP 8.1, verifies the DEB checksum, and the installed PHP CLI reports the gRPC extension.

**Step 5: Commit**

`git add tools/install/install.sh tools/install/test_install.sh tools/test/component/Dockerfile_deb_installer packaging/nfpm.yaml`

`git commit -m "feat: install grpc from approved apt repository"`

### Task 3: Verify scoped gRPC export through the installer artifact

**Objective:** Prove the PPA-provisioned extension, scoped Composer transport provider, and real OTLP/gRPC response path work together in the installer-backed DEB environment.

**Files:**
- Review/modify only if required: `tests/OTelDistroTests/ComponentTests/PackagesPhpRequirementTest.php`
- Review/modify only if required: `tests/OTelDistroTests/ComponentTests/DeclarativeConfigGrpcTest.php`
- Review/modify only if required: `tests/OTelDistroTests/ComponentTests/TestData/declarative_config_grpc_test.yaml`
- Validate: `tools/test/component/test_packages_one_matrix_row_in_docker.sh`
- Validate: `tools/build/build_php_code_for_packages.sh`
- Validate: `tools/build/build_packages.sh`

**Step 1: Establish expected behaviour**

Keep the installer-backed Ubuntu 22.04 amd64 PHP 8.1 row as the only gRPC extension contract. The installed PHP process must show the gRPC extension loaded, resolve the scoped `GrpcTransportFactory` for `grpc`, export an explicit named span through declarative `otlp_grpc` configuration, flush successfully, deserialize the collector response, and verify the receiver result.

**Step 2: Add or update validation**

Retain or add the narrowest assertions that separately surface extension activation, scoped `Registry::transportFactory('grpc')` provider discovery, child-process stderr, and real receiver output. Do not replace the component assertion with a direct extension-only check or bypass the gRPC client.

**Step 3: Write minimal implementation**

Adjust only the existing installer-backed test input and assertions required to expose failures from the PPA installation, transport provider registration, or response descriptor decoding. Preserve the declarative batch `otlp_grpc` fixture, the scoped dependency boundary, and the PECL-free installer contract.

**Step 4: Run test to verify pass**

Run:

`./tools/build/build_php_code_for_packages.sh --php_versions '81 82 83 84 85'`

`./tools/build/build_packages.sh --package_version <approved-new-version> --build_architecture linux-x86-64 --package_goarchitecture amd64 --package_types 'deb'`

Then ask the user to run:

`OTEL_PHP_TESTS_GROUP=requires_external_services OTEL_PHP_TESTS_INSTALLER_BACKED=true OTEL_PHP_LOG_LEVEL_STDERR=DEBUG ./tools/test/component/test_packages_one_matrix_row_in_docker.sh --matrix_row '8.1,deb,cli,with_ext_svc' --packages_dir "$PWD/_BUILT/packages" --logs_dir "$PWD/_BUILT/ubuntu_grpc_component_logs"`

Expected: the installer-backed container uses PPA `php8.1-grpc`; the scoped provider resolves; the response decodes without descriptor-pool errors; and the receiver observes the named span.

**Step 5: Commit**

`git add tests/OTelDistroTests/ComponentTests tools/test/component`

`git commit -m "test: verify installer apt grpc export"`
