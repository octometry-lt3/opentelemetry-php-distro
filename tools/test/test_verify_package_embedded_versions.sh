#!/usr/bin/env bash
set -e -o pipefail

repo_root_dir="$(realpath "$(dirname "${BASH_SOURCE[0]}")/../..")"
test_dir="$(mktemp -d "${TMPDIR:-/tmp}/test_verify_package_embedded_versions.XXXXXX")"
trap 'rm -rf "${test_dir}"' EXIT

php_code_dir="${test_dir}/php_code_for_packages"
native_build_dir="${test_dir}/native_build"
architecture="linux-x86-64"
native_header="${native_build_dir}/${architecture}-release/libcommon/code/generated/otel_distro_version.h"

write_php_version() {
    local file_path="$1"
    local version="$2"
    mkdir -p "$(dirname "${file_path}")"
    printf "<?php\nfinal class PhpPartVersion {\n    public const VALUE = '%s';\n}\n" "${version}" >"${file_path}"
}

write_native_version() {
    local version="$1"
    mkdir -p "$(dirname "${native_header}")"
    printf '#define OTEL_DISTRO_VERSION "%s"\n' "${version}" >"${native_header}"
}

run_checker() {
    "${repo_root_dir}/tools/build/verify_package_embedded_versions.sh" \
        --build_architecture "${architecture}" \
        --php-code-dir "${php_code_dir}" \
        --native-build-dir "${native_build_dir}"
}

assert_failure() {
    local expected="$1"
    local output
    if output="$(run_checker 2>&1)"; then
        echo "Expected checker failure: ${expected}" >&2
        exit 1
    fi
    if [[ "${output}" != *"${expected}"* ]]; then
        echo "Checker failure did not contain ${expected}: ${output}" >&2
        exit 1
    fi
}

rm -rf "${test_dir}/php_code_for_packages" "${native_build_dir}"
write_php_version "${php_code_dir}/PhpPartVersion.php" "0.7.0~abc123"
write_php_version "${php_code_dir}/scoped/81/OpenTelemetry/Distro/PhpPartVersion.php" "0.7.0~abc123"
write_native_version "0.7.0~abc123"
run_checker >/dev/null

write_native_version "0.7.0~def456"
assert_failure "${php_code_dir}/PhpPartVersion.php"

write_native_version "0.7.0~abc123"
write_php_version "${php_code_dir}/PhpPartVersion.php" "0.7.0~abc123-dirty"
assert_failure "dirty"

write_php_version "${php_code_dir}/PhpPartVersion.php" "0.7.0~abc123"
write_php_version "${php_code_dir}/scoped/81/OpenTelemetry/Distro/PhpPartVersion.php" "0.7.0~abc123"
write_php_version "${php_code_dir}/scoped/82/OpenTelemetry/Distro/PhpPartVersion.php" "0.7.0~def456"
assert_failure "${php_code_dir}/scoped/82/OpenTelemetry/Distro/PhpPartVersion.php"

echo "verify_package_embedded_versions.sh fixture tests passed"
