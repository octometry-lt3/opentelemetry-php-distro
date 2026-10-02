# OpenTelemetry PHP Distro

OpenTelemetry PHP Distro provides production-ready, zero-code OpenTelemetry instrumentation for PHP applications by packaging PHP runtime/bootstrap code, a native PHP extension/loader, OpenTelemetry dependencies, and Linux package integration into installable `deb`, `rpm`, and `apk` artifacts.

## Repository boundary

- Treat this directory as the repository root; run repository scripts from here unless a command explicitly says otherwise.
- Keep reads/writes inside this repository. Durable AI memory belongs under `docs/ai/**`; read `docs/ai/README.md` before loading other AI memory files.
- Product areas are `prod/php/`, `prod/native/`, `packaging/`, `tools/`, `tests/`, `.github/workflows/`, and user/contributor docs under `docs/`.

## Working with ai-config

- Start with `docs/ai/README.md`; load only the routed memory file needed for the task.
- Persist durable architecture boundaries and invariants in `docs/ai/architecture.md`, decisions in `docs/ai/decisions/*.md`, implementation plans in `docs/ai/plans/*.md`, and continuity notes in `docs/ai/logs/YYYY-MM.md`.
- Keep memory updates concise and factual; do not store transcripts, raw diffs, long terminal output, or temporary assumptions.

## Layout

- `prod/php/` is the PHP runtime/bootstrap code; `prod/native/` is the C/C++ loader and extension built with CMake/Conan.
- `packaging/` contains OS-package install metadata and scripts. `tools/build/` and `tools/test/` are the Docker-based build and CI entrypoints.
- `tests/OTelDistroTests/UnitTests` contains PHP unit tests; `tests/OTelDistroTests/ComponentTests` contains PHP component tests.
- `.github/workflows/build.yml` is the main CI orchestrator; the final `ci` job is the branch-protection gate.
- Supported PHP minors, package types, build architectures, and the release version are defined in `project.properties`; update its PHP matrix alongside every native/packaging PHP-version change.

## PHP Dependencies And Checks

- Do not run ordinary `composer install` or `composer update`: they are blocked because this repository commits per-PHP, per-environment locks in `generated_composer_lock_files/` and deliberately ignores the root `composer.lock`.
- Install the lock matching the local PHP version with `./tools/build/install_PHP_deps_in_dev_env.sh`. It also generates PHP build templates required by PHP scripts in `tools/build/`.
- After changing Composer constraints, run `./tools/build/generate_composer_lock_files.sh && ./tools/build/install_PHP_deps_in_dev_env.sh` and commit the generated lockfiles. These commands require Docker.
- Run PHP checks with `composer run-script static_check`, unit tests with `composer run-script run_unit_tests`, and all of them in CI order with `composer run-script static_check_and_run_unit_tests`.
- Run one unit test with `composer run-script run_unit_tests -- --filter 'TestName'`; use `composer run-script run_component_tests -- --filter 'TestName'` for component tests. The scripts disable the distro and all auto-instrumentations so local dependencies cannot affect results.
- CI’s authoritative PHP check is `./tools/test/test_php_static_and_unit.sh --php_versions '81 82 83 84 85' --logs_dir "$PWD/_BUILT/unit_tests_logs"`; it runs Dockerized production static analysis plus test static/unit checks for each PHP minor.

## Code Style

- PHP style is enforced by PHP_CodeSniffer (`composer run-script php_codesniffer_check`) using PSR-12, strict types, max 200-character lines, and Slevomat unused-use checks; autofix with `composer run-script php_codesniffer_fix`.
- PHP static analysis uses PHPStan at max level via `composer run-script phpstan` or the combined `composer run-script static_check`.
- Native formatting is defined by `prod/native/.clang-format`, based on Google style with 4-space indentation.

## Git Workflow

- Work on a topic branch and open a PR; upstream protected branches are validated by GitHub Actions.
- Use concise conventional-style commit subjects seen in release notes, such as `feat: ...`, `fix: ...`, `docs: ...`, `ci: ...`, or `chore: ...`.
- Before requesting merge, run the smallest relevant local checks and note any skipped Docker/matrix validation in the PR.
- For releases, merge the preparation PR to `main`, then tag exactly `v${version}` from `project.properties`; the release workflow verifies this invariant.

## Native And Package Validation

- Build native code as CI does with `./tools/build/build_native.sh --build_architecture linux-x86-64`; it runs native unit tests unless `--skip_unit_tests` is supplied. Use `--skip_configure` only when no build configuration inputs changed.
- Run native PHPT tests only after a matching native build: `./tools/build/test_phpt.sh --build_architecture linux-x86-64 --php_versions '81 82 83 84 85'`.
- Build packaged PHP dependencies with `./tools/build/build_php_code_for_packages.sh --php_versions '81 82 83 84 85'` before `./tools/build/build_packages.sh`; package/component tests consume built package artifacts rather than the source-tree PHP code.

## Generated And Local-Dependency Files

- Do not edit or commit ignored generated files under `prod/php/OpenTelemetry/Distro/` (`PhpPartVersion.php`, `Log/LogFeature.php`) or `prod/php/ScoperConfig.php`.
- For an unreleased local Composer package, use the gitignored `.local-repos.json` and pass it to `generate_composer_lock_files.sh --local-repos-file`; never commit those locally generated locks or the temporary `composer.json` requirement.
- Do not substitute source-tree PHP dependencies for built package artifacts in package/component tests.
- Do not run Docker-heavy matrix checks casually when a smaller targeted validation is sufficient; reserve full matrix commands for release/CI parity or high-risk changes.
