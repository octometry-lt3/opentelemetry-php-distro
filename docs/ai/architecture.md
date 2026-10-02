# Architecture

## Scope

This repository builds and releases the OpenTelemetry PHP Distro: a production-ready, zero-code instrumentation distribution for PHP applications. It combines PHP runtime/bootstrap code, a native PHP extension/loader, OpenTelemetry PHP dependencies, package install metadata, and Docker-based build/test automation into installable Linux packages (`deb`, `rpm`, `apk`).

Primary deliverables:

- PHP runtime/bootstrap files under `prod/php/`.
- Native C/C++ loader, PHP extension, and support libraries under `prod/native/`.
- OS-package configuration and lifecycle scripts under `packaging/`.
- Dockerized build and CI entrypoints under `tools/build/` and `tools/test/`.
- Generated Composer lock files for each supported PHP/environment matrix under `generated_composer_lock_files/`.

The supported PHP minors, package types, build architectures, release version, and related matrix values are centralized in `project.properties`.

## Boundaries

Architectural boundaries:

- `prod/php/` owns PHP-side runtime/bootstrap behavior and Composer-managed dependencies.
- `prod/native/` owns native loader/extension behavior, CMake/Conan configuration, and native unit/PHPT validation.
- `packaging/` owns package metadata and install/uninstall integration with system PHP configuration paths.
- `tools/build/` and `tools/test/` are the preferred interfaces for local and CI builds/tests; avoid ad hoc replacement commands unless investigating a specific issue.
- `docs/ai/` is durable AI memory. Read `docs/ai/README.md` first and load only the routed memory needed for the task.

Key invariants:

- Keep `project.properties` synchronized with any supported PHP-version, package-type, architecture, protocol, semantic-convention, or release-version change.
- Do not run ordinary `composer install` or `composer update`; use `./tools/build/install_PHP_deps_in_dev_env.sh` locally and regenerate committed lock files only through `./tools/build/generate_composer_lock_files.sh` when Composer constraints change.
- Component/package tests consume built package artifacts, not source-tree PHP code.
- Native PHPT tests require a matching native build first.
- Package install behavior enables the distro by writing/symlinking PHP INI files that load `/opt/opentelemetry/php/distro/opentelemetry_php_distro_loader.so` and point to the packaged PHP bootstrap file.
- Release tags must match `v${version}` from `project.properties`.

Explicit don'ts:

- Do not edit ignored generated PHP files under `prod/php/OpenTelemetry/Distro/` or `prod/php/ScoperConfig.php` directly.
- Do not commit locally generated Composer locks or temporary Composer requirements created for unreleased local packages.
- Do not update native/packaging PHP-version support without updating the PHP matrix in `project.properties` and related generated locks/tests.
- Do not treat source-tree PHP dependencies as substitutes for packaged artifacts in package/component validation.

## Validation Commands

Local PHP setup and checks:

- Install the lock matching the local PHP version: `./tools/build/install_PHP_deps_in_dev_env.sh`
- PHP static checks: `composer run-script static_check`
- PHP unit tests: `composer run-script run_unit_tests`
- Static checks plus unit tests: `composer run-script static_check_and_run_unit_tests`
- One PHP unit test: `composer run-script run_unit_tests -- --filter 'TestName'`
- PHP component test: `composer run-script run_component_tests -- --filter 'TestName'`
- PHP_CodeSniffer autofix: `composer run-script php_codesniffer_fix`

Authoritative/CI-style checks:

- PHP static/unit matrix: `./tools/test/test_php_static_and_unit.sh --php_versions '81 82 83 84 85' --logs_dir "$PWD/_BUILT/unit_tests_logs"`
- Native build plus native unit tests: `./tools/build/build_native.sh --build_architecture linux-x86-64`
- Native PHPT tests after matching native build: `./tools/build/test_phpt.sh --build_architecture linux-x86-64 --php_versions '81 82 83 84 85'`
- Build packaged PHP dependencies before packages: `./tools/build/build_php_code_for_packages.sh --php_versions '81 82 83 84 85'`
- Build packages: `./tools/build/build_packages.sh --package_version <version> --build_architecture linux-x86-64 --package_goarchitecture amd64 --package_types 'deb rpm'`

Composer constraint changes:

- Regenerate and install locks: `./tools/build/generate_composer_lock_files.sh && ./tools/build/install_PHP_deps_in_dev_env.sh`

## Tooling and Style

- PHP style is enforced by PHP_CodeSniffer (`phpcs.xml`): PSR-12, strict types, max 200-character lines, and Slevomat unused-use checks.
- PHP static analysis uses PHPStan at max level through Composer scripts.
- Native formatting is defined in `prod/native/.clang-format` and is based on Google style with 4-space indentation.
- CI is orchestrated by GitHub Actions, with `.github/workflows/build.yml` as the main workflow and a final `ci` gate job.

## Risks and Unknowns

- Many authoritative checks require Docker and can be expensive.
- PHP version support is represented in multiple places; stale docs or generated files are a known risk when changing PHP minors.
- Some development documentation examples may still mention PHP versions through 8.4 while current project metadata includes PHP 8.5.
