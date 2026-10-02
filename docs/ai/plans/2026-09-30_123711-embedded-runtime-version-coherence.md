---
status: completed
created: 2026-09-30
task: 2/2
required_gates:
  clean_source_checkout: true
  full_php_matrix_payload: true
  clean_container_deb_install: true
  sha512_replacement_artifact: true
  architect_review: false
  memory_update: false
---

# Embedded PHP/native Distro version coherence implementation plan

**Goal:** Prevent package assembly from combining PHP and native payloads stamped from different Git states, and ship a `0.7.0~lt3.4` DEB whose installed PHP and native Distro versions match exactly without `-dirty`.

**Architecture:** PHP version stamping occurs in `configure_php_templates.sh` before the PHP package payload is copied to `_BUILT/php_code_for_packages`; native stamping occurs during CMake configure and is retained in `prod/native/_build`. `build_packages.sh` subsequently consumes both persistent directories without regenerating or relating them. Add an assembly-time provenance guard that compares the actual stamped payload inputs, then extend installed-package smoke validation to compare the runtime values exposed by `php --ri opentelemetry_distro`; neither check changes the existing runtime integrity guard.

**Tech Stack:** Bash, CMake, Docker, nfpm, Debian packages, PHP 8.1–8.5, PHP-Scoper.

**Scope classification:** `multi-task`

## Invariants

- Keep the fixture/runtime embedded-version integrity guard strict; do not accept or strip a `-dirty` suffix.
- Do not change gateway filtering, Laravel behavior, OTLP/gRPC, scoped dependencies, or scoped-bridge behavior.
- The guard must inspect the exact native and PHP payload inputs nfpm will package, not Git state alone.
- Build/package metadata `--package_version` remains independent from the embedded runtime Distro version.
- A replacement artifact must be built from a clean primary checkout (not a linked worktree whose `.git` points outside the Docker mount), with both PHP payload and native output regenerated from that checkout.

## Tasks

### Task 1: Reject mismatched PHP/native payload stamps before nfpm assembly

**Objective:** Make `build_packages.sh` fail clearly before creating a package when `_BUILT/php_code_for_packages` and `prod/native/_build` contain different embedded Distro versions.

**Files:**
- Create: `tools/build/verify_package_embedded_versions.sh`
- Modify: `tools/build/build_packages.sh`
- Create: `tools/test/test_verify_package_embedded_versions.sh`
- Review: `tools/build/configure_php_templates.sh`, `prod/native/building/cmake/otel_get_git_version.cmake`, `packaging/nfpm.yaml`
- Validate: synthetic PHP/native stamp fixtures and the package-build preflight path

**Step 1: Establish expected behaviour**

Record the two package inputs that nfpm copies: every generated `PhpPartVersion.php` under `_BUILT/php_code_for_packages` (including scoped PHP-minor payloads) and `OTEL_DISTRO_VERSION` in `prod/native/_build/<architecture>-release/libcommon/code/generated/otel_distro_version.h`. A preflight succeeds only when all discovered PHP stamps equal the native stamp exactly; it fails before nfpm invocation if an input is absent, PHP minors disagree, either value has `-dirty`, or the PHP/native values differ. Its failure output must name the concrete source files and observed values.

**Step 2: Add or update validation**

Add a focused shell test that creates temporary synthetic PHP payload and native-header layouts. Cover: matching clean values pass; stale PHP versus native fails; a PHP `-dirty` value fails; and mixed PHP-minor values fail. Reuse the repository’s shell-test conventions and make the checker accept explicit fixture paths/architecture so the test does not depend on `_BUILT` or Docker.

**Step 3: Write minimal implementation**

Implement the checker as a narrow build helper that extracts `PhpPartVersion::VALUE` from the exact copied PHP files and `OTEL_DISTRO_VERSION` from the native generated header. Invoke it in `build_packages.sh` after argument validation and before `nfpm`/checksum generation, passing the same architecture and paths used by `packaging/nfpm.yaml`. Do not regenerate, overwrite, or infer artifacts from `git`; report stale or incoherent build inputs and require the caller to rerun the native and PHP payload builds from one clean checkout.

**Step 4: Run test to verify pass**

Run:

`./tools/test/test_verify_package_embedded_versions.sh`

Then intentionally point the checker at mismatched synthetic or preserved artifact values.

Expected: matching clean stamps exit zero; each mismatch exits nonzero before package assembly with a diagnostic identifying the PHP/native source and values.

**Step 5: Commit**

`git add tools/build/verify_package_embedded_versions.sh tools/build/build_packages.sh tools/test/test_verify_package_embedded_versions.sh`

`git commit -m "fix: verify package runtime version stamps"`

### Task 2: Verify installed DEB version coherence and produce the replacement artifact

**Objective:** Assert the installed package exposes identical clean PHP/native Distro versions, then create and checksum the replacement `0.7.0~lt3.4` DEB from coherent build inputs.

**Files:**
- Create: `packaging/test/verify_embedded_versions.sh`
- Modify: `tools/build/build_packages.sh:test_package` or the existing package smoke-test invocation path
- Review/modify only if required: `packaging/test/smokeTest.php`
- Validate: `php --ri opentelemetry_distro` in the package test container and `_BUILT/packages/opentelemetry-php-distro_0.7.0~lt3.4_amd64.deb{,.sha512}`

**Step 1: Establish expected behaviour**

After installing the generated DEB into a clean PHP image, run `php --ri opentelemetry_distro` and extract the native and PHP part version lines that the existing fixture guard compares. Both lines must be present, equal byte-for-byte, and not end in `-dirty`; missing/unparseable lines are failures. Keep the raw `php --ri` output in the failing test log.

**Step 2: Add or update validation**

Add a shell verifier used by the existing `test_package()` Docker install-smoke flow immediately after `dpkg -i` and before the general PHP smoke test. Unit-test the parser with captured/fixture `php --ri` text if the repository has a shell-fixture convention; otherwise make the verifier accept an explicit report file for narrow fixture coverage. Preserve package uninstall and normal smoke coverage.

**Step 3: Write minimal implementation**

Implement only the installed-version comparison and wire it into deb packaging smoke validation. Do not alter the loader’s mismatch behavior. Ensure `build_packages.sh` performs Task 1’s preflight before creating the DEB and generates the existing `.sha512` only after the install smoke passes.

**Step 4: Run test to verify pass**

From the clean primary checkout, first confirm no tracked changes:

`git diff-index --quiet HEAD -- && git -C prod/native diff-index --quiet HEAD --`

Regenerate both package inputs from that same checkout:

`./tools/build/build_native.sh --build_architecture linux-x86-64`

`./tools/build/build_php_code_for_packages.sh --php_versions '81 82 83 84 85'`

Build and smoke-install the replacement:

`./tools/build/build_packages.sh --package_version '0.7.0~lt3.4' --build_architecture linux-x86-64 --package_goarchitecture amd64 --package_types 'deb'`

Expected: preflight and clean-container install pass; `php --ri opentelemetry_distro` reports identical clean PHP/native versions; `_BUILT/packages/opentelemetry-php-distro_0.7.0~lt3.4_amd64.deb` and its generated `.sha512` exist and verify with `sha512sum -c`.

**Step 5: Commit**

`git add packaging/test/verify_embedded_versions.sh tools/build/build_packages.sh packaging/test/smokeTest.php`

`git commit -m "test: verify installed distro part versions"`
