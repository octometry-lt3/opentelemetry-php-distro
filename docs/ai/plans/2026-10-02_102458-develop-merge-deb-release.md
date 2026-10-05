---
status: in-progress
created: 2026-10-02
task: 2/2
required_gates:
  architect_review: true
  memory_update: true
---

# Develop-to-main Debian release implementation plan

**Goal:** A merged same-repository `develop` → `main` PR publishes one immutable GitHub release containing a verified amd64 DEB, its SHA-512 checksum, and the Ubuntu 22.04 amd64 installer; retire all other Actions workflows.

**Architecture:** Replace the current tag-triggered release and reusable workflow graph with a single workflow triggered on merged PRs targeting `main`, guarded by source branch and repository. Build from the PR merge commit using the repository's existing Docker-based native, PHP payload, and DEB build scripts; publish `v${version}` from `project.properties` only after the DEB, checksum, and installer have passed validation. Preserve the installer-owned PPA/gRPC boundary and the `v${version}` release invariant.

**Tech Stack:** GitHub Actions, GitHub Releases/`gh`, Bash, Docker, CMake/Conan via repository scripts, nfpm, SHA-512, Ubuntu 22.04 amd64.

**Scope classification:** `multi-task` (2 deliverables).

**Repository evidence:** `.github/workflows/` has 16 workflows; `build.yml` provides the existing `ci` branch-protection gate and the native/PHP/package build graph. `release.yml` currently publishes only on matching `v*` tags, creates a draft, downloads assets to verify SHA-512, then publishes. `build_packages.sh` generates `.deb.sha512` after its DEB smoke test and needs both a native build and `_BUILT/php_code_for_packages`; `tools/install/install.sh` downloads a versioned amd64 DEB and SHA-512 and defaults to literal `0.7.0`. `tools/install/test_install.sh --deb <path> --version <semver>` provides a Docker installer fixture test; `docs/getting-started/setup.md` describes the currently unreleased installer. `docs/ai/architecture.md` and root `AGENTS.md` describe the soon-to-be-removed `ci` gate/tag release process.

**Pre-implementation gates / blocking decisions:**
- Release owner must supply the **next unused semantic version** for the first `develop` → `main` release (do not reuse `0.7.0` without confirming no tag/release exists). Update `project.properties` and installer default together before merging; later releases require the same coordinated version bump. Do not invent a version in implementation.
- Repository administrator must remove or replace branch-protection rules requiring the deleted `ci` job or other deleted workflow checks before the PR is merged. Confirm Actions may write contents/create releases, and the existing Docker build image `otel/opentelemetry-php-distro-dev:native-build-linux-x86-64-gcc15.2.0-v0.0.2-conancache-v0.0.2` is accessible to runners; do not delete the image-build workflow without acknowledging that ongoing image publication stops.
- Architecture review before implementation: retiring PR CI/security/scorecard workflows and changing tag-driven releases to PR-merge-driven publication is an intentional boundary change. After implementation, update routed `docs/ai/architecture.md` and relevant decision/memory for the new release contract (memory-update gate, not part of product implementation).
- A release from the same PR that installs the new workflow may be constrained by GitHub's default-branch workflow registration/event timing: verify on a nonproduction test merge or plan a controlled first release; never bypass the unique-version and checksum checks.
- If verification fails after draft creation, leave the draft unpublished and require maintainer review/cleanup or a new version before retry; an automatic rerun must not overwrite an existing tag or draft.
- **Human validation gate:** All validation that builds images or starts containers (including repository build scripts and `tools/install/test_install.sh`) is performed by the user, not by the coding agent. The agent stops after non-container checks and hands over the exact commands and expected outcomes below; obtain human confirmation before treating container-based validation as passed or merging. This does not remove the build and package smoke-test steps from the intended GitHub release workflow itself.

## Tasks

### Task 1: Align the versioned installer and release documentation

**Objective:** Make the first new release's version, installer default, and user-facing download instructions agree without widening the supported host boundary.

**Files:**
- Modify: `project.properties` (approved new version only)
- Modify: `tools/install/install.sh` (`DEFAULT_VERSION` and help text, retain PPA fingerprint and checksum verification)
- Modify: `tools/install/test_install.sh` (only if needed to keep the test default in sync)
- Modify: `docs/getting-started/setup.md` (replace unreleased/old-version claims with release instructions, supported platform, version and checksum example)
- Validate (agent): `bash -n`, static version consistency
- Validate (human, after Task 2 build): `tools/install/test_install.sh` with the selected DEB fixture

**Step 1: Establish expected behaviour**

Agree on an unused `v<version>`; `project.properties`, installer default/help, and documentation all reference the same release. Installer still rejects unsupported hosts and bad checksums and still installs only a verified amd64 DEB on Ubuntu 22.04.

**Step 2: Add or update validation**

Keep the existing installer fixture test for human execution and compare the installer default to `project.properties` as part of the release workflow preflight (Task 2), rather than building a new test harness. Ensure the test fixture accepts the chosen explicit `--version` and exercises the published release-shaped filename.

**Step 3: Write minimal implementation**

Apply the approved version in the three existing version locations, update setup docs, and avoid changing the installer provisioning logic or the DEB's APT dependency contract.

**Step 4: Run test to verify pass**

Agent run: `bash -n tools/install/install.sh tools/install/test_install.sh`; inspect `project.properties`, `DEFAULT_VERSION`, `tools/install/test_install.sh` default and setup docs for the same chosen version. After Task 2 has produced the package, request the human to run the full Docker installer fixture.

Expected: syntax passes and all defaults and docs agree. Do not claim the Docker installer test passes until a matching DEB exists.

**Step 5: Commit (implementation agent only, after Task 1 validation)**

`git add project.properties tools/install/install.sh tools/install/test_install.sh docs/getting-started/setup.md` (only touched files)
`git commit -m "docs: align installer with deb release"`

### Task 2: Replace all workflows with one gated DEB release flow

**Objective:** Publish only the validated amd64 DEB, its matching `.sha512`, and `install.sh` for merged same-repository `develop` → `main` PRs; remove the other 15 workflow files.

**Files:**
- Modify: `.github/workflows/release.yml` (single self-contained workflow)
- Delete: the other 15 files currently in `.github/workflows/` (`audit-php-deps.yml`, `build-arch-matrix-generator.yml`, `build-native.yml`, `build-packages.yml`, `build-php-code-for-packages.yml`, `build.yml`, `generate-component-tests-matrix.yml`, `generate-php-versions.yml`, `native-dev-tools-build.yml`, `scorecard.yml`, `test-otel-unit.yml`, `test-packages-component-tests.yml`, `test-php-static-and-unit.yml`, `test-phpt.yml`, `zizmor.yml`)
- Validate (agent): workflow trigger/condition, YAML/shell syntax, asset-path and version consistency
- Validate (human): native/PHP/package/installer builds, checksum on built artifact, draft/downloaded release assets

**Step 1: Establish expected behaviour**

Use `pull_request` `closed` targeting `main` with a job guard requiring `merged == true`, `head.ref == develop`, and `head.repo.full_name == github.repository`; direct pushes, tag pushes, other PRs, and fork-named `develop` branches cannot publish. Checkout the event's `merge_commit_sha` (not a moving main HEAD or the PR head). Restrict write permission to the publishing job; serialize release publication without canceling an in-flight release. Fail before building if the tag **or** release already exists or the version/default are inconsistent; do not overwrite or retag existing releases.

**Step 2: Add or update validation**

The agent uses `actionlint .github/workflows/release.yml` if already available (otherwise records it as unavailable rather than installing a containerized validator), a YAML/event-condition review for both positive and negative scenarios, and `bash -n` for modified Bash. In the workflow, validate expected asset names and count exactly one amd64 DEB/checksum pair, run `sha512sum --check`, and verify the draft's downloaded assets before publishing; ensure failures leave no *published* release and do not silently reuse an old tag. The user runs existing package smoke and installer fixture tests as manual gates.

**Step 3: Write minimal implementation**

Implement the native build with `./tools/build/build_native.sh --build_architecture linux-x86-64` (keep native unit tests), the PHP payload with `./tools/build/build_php_code_for_packages.sh --php_versions "${PROJECT_PROPERTIES_SUPPORTED_PHP_VERSIONS//[()]/}"`, and the DEB with `./tools/build/build_packages.sh --package_version "$PROJECT_PROPERTIES_VERSION" --build_architecture linux-x86-64 --package_goarchitecture amd64 --package_types 'deb' --package_sha "$MERGE_SHA"`; source `tools/read_properties.sh` as existing workflows do. Use a Docker-capable amd64 runner, allow adequate timeout for build scripts, and preserve existing smoke checks. Run `tools/install/test_install.sh` against the actual built DEB before publication if runner/network supports its Ubuntu 22.04/PPA Docker fixture. Publish only the DEB, its matching checksum, and `tools/install/install.sh` as `install.sh`; use `gh release create` for a draft with `--target <merge_commit_sha>`, verify downloaded draft assets and checksums, then `gh release edit --draft=false`. Do not depend on removed `workflow_call` files, push compiler images, or upload RPM/APK/arm64/debug assets. Remove the 15 other workflows only after the standalone YAML no longer references them.

**Step 4: Run test to verify pass**

Agent run (no containers): `bash -n tools/install/install.sh tools/install/test_install.sh`; `actionlint .github/workflows/release.yml` only if installed locally and non-containerized. Confirm `.github/workflows/` contains exactly `release.yml`, inspect workflow conditions for authorized and rejected events, and statically review the asset filenames, version-default preflight, tag-existence guard, and SHA-512 check. Report any unavailable static validator; do not build images or launch containers.

Human run, in order, on a Docker-capable amd64 host: `./tools/build/build_native.sh --build_architecture linux-x86-64`; `./tools/build/build_php_code_for_packages.sh --php_versions '81 82 83 84 85'`; `./tools/build/build_packages.sh --package_version '<version>' --build_architecture linux-x86-64 --package_goarchitecture amd64 --package_types 'deb'`; `sha512sum --check _BUILT/packages/opentelemetry-php-distro_<version>_amd64.deb.sha512`; `./tools/install/test_install.sh --deb "$PWD/_BUILT/packages/opentelemetry-php-distro_<version>_amd64.deb" --version '<version>'`. The human then exercises an authorized test merge, confirms the release tag points to the merge commit and all three public assets are downloadable, and confirms nonmatching events do not publish. Record and report validation outcomes to the coding agent.

Expected: agent static checks pass; human reports that versioned DEB smoke and installer fixture pass, checksum checks pass both before and after draft upload, no other event publishes, and no old tag/release is mutated. Do not mark the human gate complete without the user's results. Removing independent CI/security workflows intentionally eliminates their checks; do not report equivalent coverage unless explicitly run within the replacement.

**Step 5: Commit (implementation agent only, after validation)**

`git add .github/workflows/` (review staged paths: only the 15 workflow deletions and release.yml change)
`git commit -m "ci: publish verified deb on develop merge"`
